<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Scheduler;

use Globetrotters\AiPresenceBundle\Analytics\FlushGate;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * symfony/scheduler wiring (schedule name "gt" → messenger:consume
 * scheduler_gt). Registered only when scheduler + messenger are installed;
 * the cron'd gt:refresh command is the default lane.
 */
final class RefreshScheduleProvider implements ScheduleProviderInterface
{
    /**
     * How often the schedule *asks* for a flush — not how often one happens.
     *
     * {@see FlushGate} owns the 15-minute cadence, and the flusher enforces it
     * under the flush lock for every lane, this one included. Polling at
     * exactly that interval would race the gate: a stamp that lands a second
     * after its trigger leaves the next trigger a second short of due, and
     * every other flush would be skipped. Polling every five minutes, like the
     * documented cron line, keeps the real cadence between 15 and 20 minutes.
     */
    private const FLUSH_POLL = '5 minutes';

    public function __construct(
        private readonly Options $options,
        private readonly CacheItemPoolInterface $pool,
    ) {
    }

    public function getSchedule(): Schedule
    {
        $every = 'weekly' === $this->options->refreshInterval() ? '1 week' : '1 day';

        $schedule = (new Schedule())
            ->add(RecurringMessage::every($every, new RefreshMessage()))
            // Reporting rides the same schedule object but deliberately not the
            // same cadence: refresh_interval is a content-freshness choice the
            // customer makes (down to weekly), while the flush interval is
            // fixed by the ingest contract at 15 minutes. Sharing the cadence
            // would let a weekly refresh sit the buffer for days, well past the
            // backend's 90-minute staleness window, where hits are re-stamped
            // to arrival time and land in the wrong buckets.
            ->add(RecurringMessage::every(self::FLUSH_POLL, new FlushMessage()));

        // Collapse the runs a stopped worker missed into one. The option arrived
        // in Symfony 7.1; 6.4 LTS replays each missed run instead, which both
        // handlers absorb: a replayed flush finds the interval not yet due under
        // the flush lock and sends nothing, and a replayed refresh is an
        // idempotent re-pull. Detected through reflection rather than
        // method_exists(), which static analysis — run against a single Symfony
        // version — would fold into a constant.
        if ((new \ReflectionClass($schedule))->hasMethod('processOnlyLastMissedRun')) {
            $schedule->processOnlyLastMissedRun(true);
        }

        // stateful() persists the last run so missed runs survive worker
        // restarts, but it requires a Symfony cache contract. The bundle only
        // guarantees a PSR-6 pool (see the cache_pool config), so degrade
        // gracefully when the configured pool isn't a CacheInterface instead of
        // failing the whole scheduler lane with a container TypeError.
        if ($this->pool instanceof CacheInterface) {
            $schedule->stateful($this->pool);
        }

        return $schedule;
    }
}
