<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Scheduler;

use Globetrotters\AiPresenceBundle\Settings\Options;
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
    public function __construct(
        private readonly Options $options,
        private readonly CacheInterface $pool,
    ) {
    }

    public function getSchedule(): Schedule
    {
        $every = 'weekly' === $this->options->refreshInterval() ? '1 week' : '1 day';

        return (new Schedule())
            ->add(RecurringMessage::every($every, new RefreshMessage()))
            ->stateful($this->pool)
            // A full re-pull is idempotent — collapse missed runs.
            ->processOnlyLastMissedRun(true);
    }
}
