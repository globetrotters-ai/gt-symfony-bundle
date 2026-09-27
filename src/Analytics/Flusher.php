<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

use Globetrotters\AiPresenceBundle\GlobetrottersAiPresenceBundle;
use Symfony\Component\Clock\ClockInterface;

/**
 * One flush: claim the oldest events, fit them under the wire caps, POST, and
 * only then drop them from the buffer.
 *
 * Two properties matter more than anything else here.
 *
 * **Retry must not regenerate IDs.** A flush that times out may well have been
 * received. Events are deleted only after a 2xx, so the next attempt re-claims
 * the same events and therefore re-sends the same UUIDs — and the backend
 * dedupes on ``id``, so a retry cannot double-count. UUIDs are minted once, in
 * {@see EventRecorder}, and never here.
 *
 * **Failure is quiet.** Any non-2xx or timeout means "retry later" and nothing
 * else; ``429`` additionally means back off and re-send the *same* payload. A
 * flush never affects a served response and never surfaces an error to a
 * visitor.
 *
 * Note that ``202`` is not confirmation the data was good: the endpoint answers
 * it for a bad token, an unknown install and a malformed body too. All this
 * class can honestly record is that a batch was accepted.
 *
 * **Page views ride the same envelope.** When the first-party page-view
 * counter is in use, every run first seals the UTC days that have ended —
 * which is where each record gets its UUID, once — and deletes days older than
 * the backend accepts. Sealed records then go out under ``pageViews`` beside
 * the events, with the same rule: deleted only after a 2xx, so a retry
 * re-sends the same ids. Today is never sent. None of this depends on agent
 * traffic: a run with no event buffered still carries the page views, which is
 * what makes a page-view-only envelope (``events: []``) possible.
 */
final class Flusher
{
    /**
     * Batches one run will send before giving the buffer back to the schedule.
     *
     * A backlog bigger than one batch must not take an hour to drain: hits
     * older than 90 minutes when the flush lands are re-stamped to arrival
     * time, which silently mis-buckets them. Five batches covers the entire
     * buffer bound in a single run and stays two orders of magnitude under the
     * 60-batches-per-minute per-org ceiling.
     */
    public const MAX_BATCHES_PER_RUN = 5;

    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly IngestTransportInterface $transport,
        private readonly AnalyticsOptions $options,
        private readonly AnalyticsState $state,
        private readonly FlushGate $gate,
        private readonly ClockInterface $clock,
        private readonly ?PageViewCounter $pageViews = null,
        private readonly ?PageViewOptions $pageViewOptions = null,
    ) {
    }

    /**
     * Run one flush and report what happened.
     *
     * The interval is checked *inside* the exclusive lock, for every lane. A
     * check made before taking the lock is a race two lanes can both win: a
     * cron'd command and a Scheduler worker a second apart would each see
     * "due", then take the lock in turn and each send a batch. Under the lock
     * the second one finds the first one's stamp.
     *
     * @param string $lane       which scheduling lane triggered this, for the status command
     * @param int    $maxBatches batches to drain before yielding
     * @param bool   $force      skip the interval (``gt:presence:flush --force``) — never the lock,
     *                           which is what stops two processes sending the same claimed events
     */
    public function run(string $lane, int $maxBatches = self::MAX_BATCHES_PER_RUN, bool $force = false): FlushOutcome
    {
        if (!$this->options->isConfigured() || !$this->buffer->isUsable()) {
            return FlushOutcome::Unavailable;
        }

        $outcome = $this->gate->withLock(function () use ($lane, $maxBatches, $force): FlushOutcome {
            if (!$force && !$this->gate->isDue()) {
                return FlushOutcome::NotDue;
            }

            // Stamped up front, so a failing endpoint still holds every lane to
            // the 15-minute cadence instead of retrying on every request.
            $this->gate->stamp();

            try {
                $this->sealPageViews();

                $sent = false;
                for ($batch = 0; $batch < $maxBatches; ++$batch) {
                    if (!$this->flushOnce($lane)) {
                        break;
                    }
                    $sent = true;
                    if (0 === $this->buffer->count() && [] === $this->pendingPageViews(1)) {
                        break;
                    }
                }

                return $sent ? FlushOutcome::Accepted : FlushOutcome::Rejected;
            } catch (\Throwable $error) {
                // Same rule as capture: reporting degrades, the application
                // does not.
                $this->state->update([
                    'last_flush_attempt' => $this->now(),
                    'last_flush_error' => $error->getMessage(),
                    'last_flush_lane' => $lane,
                ]);

                return FlushOutcome::Rejected;
            }
        });

        // withLock() answers null when another process holds the lock (or, in
        // the rare case isUsable() just raced, the lock file could not open).
        return $outcome ?? FlushOutcome::Locked;
    }

    /**
     * Claim, send and settle one batch.
     */
    private function flushOnce(string $lane): bool
    {
        $this->buffer->prune();

        $claimed = $this->buffer->claim(IngestTransportInterface::MAX_EVENTS_PER_BATCH);
        $pageViews = $this->pendingPageViews(PageViewCounter::MAX_RECORDS_PER_BATCH);
        $dropped = $this->buffer->droppedPending();

        if ([] === $claimed && [] === $pageViews) {
            // Empty envelopes are health heartbeats: the backend stamps the
            // install's "producer is alive" watermark even when no agent has
            // visited since the previous run. They also carry and settle any
            // pending overflow count left after the last event was removed.
            $json = $this->encode([], $dropped, []);
            if (!$this->fits($json)) {
                return false;
            }

            $result = $this->transport->post(
                $this->options->endpoint(),
                $this->options->ingestToken(),
                $json,
            );
            $this->recordAttempt($result, $lane, 0, 0);
            if ($result->isAccepted()) {
                $this->buffer->settleDropped($dropped);
            }

            return $result->isAccepted();
        }

        $batch = $this->fit($claimed, $dropped, $pageViews);

        if ([] === $batch['events'] && [] === $batch['pageViews']) {
            if ([] !== $claimed) {
                // Nothing fits, not even one event. Drop the head rather than
                // wedge every later flush behind it — and count it, so the gap
                // stays measured.
                $this->buffer->discard([$claimed[0]->id()]);
            }

            return false;
        }

        $result = $this->transport->post(
            $this->options->endpoint(),
            $this->options->ingestToken(),
            $batch['json'],
        );

        $this->recordAttempt($result, $lane, \count($batch['events']), \count($batch['pageViews']));

        if (!$result->isAccepted()) {
            // Leave every claimed event and page-view record in place: the next
            // attempt re-sends this exact payload, same UUIDs, and the backend
            // dedupes it.
            return false;
        }

        $this->buffer->release(array_map(static fn (Event $event): string => $event->id(), $batch['events']));
        $this->pageViews?->remove(array_column($batch['pageViews'], 'id'));
        $this->buffer->settleDropped($dropped);

        return true;
    }

    /**
     * Seal the page-view days that have ended and drop the ones the backend
     * would refuse. Inside the flush lock, so only one process ever assigns a
     * day's ids.
     */
    private function sealPageViews(): void
    {
        if (null === $this->pageViews) {
            return;
        }

        // Pruning runs regardless, so counts age out past the backend's window
        // whatever the flag says.
        $today = $this->today();
        $this->pageViews->prune($today);

        // Not enabled here: nothing is sealed or sent, and nothing else is
        // deleted either. A CLI or worker missing the flag (a forgotten env
        // var) must not destroy what a correctly configured web tier counted.
        if (!$this->pageViewsEnabled()) {
            return;
        }

        $this->pageViews->seal($today);
    }

    /**
     * @return list<array{id: string, day: string, path: string, bucket: string, count: int}>
     */
    private function pendingPageViews(int $limit): array
    {
        if (null === $this->pageViews || !$this->pageViewsEnabled()) {
            return [];
        }

        return $this->pageViews->pending($limit);
    }

    private function pageViewsEnabled(): bool
    {
        return null === $this->pageViewOptions || $this->pageViewOptions->isEnabled();
    }

    private function today(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }

    /**
     * Reduce a claim until its envelope fits under the backend's wire caps.
     *
     * Halves rather than trimming one item at a time: an oversize batch is
     * rare, and halving converges in a handful of encodes instead of hundreds.
     * Page-view records give way first — halved down to one before a single
     * event is held back, since events age out of the backend's 90-minute
     * window and closed days have a week — then events halve, and at one of
     * each the record steps aside so the event can be tried alone. Whatever is
     * left over stays buffered for the next batch rather than being discarded.
     *
     * @param list<Event>                                                                    $claimed
     * @param list<array{id: string, day: string, path: string, bucket: string, count: int}> $pageViews
     *
     * @return array{events: list<Event>, pageViews: list<array{id: string, day: string, path: string, bucket: string, count: int}>, json: string}
     */
    private function fit(array $claimed, int $dropped, array $pageViews): array
    {
        $events = \count($claimed);
        $records = \count($pageViews);

        while ($events > 0 || $records > 0) {
            $eventSlice = \array_slice($claimed, 0, $events);
            $recordSlice = \array_slice($pageViews, 0, $records);
            $json = $this->encode($eventSlice, $dropped, $recordSlice);

            if ($this->fits($json)) {
                return ['events' => $eventSlice, 'pageViews' => $recordSlice, 'json' => $json];
            }

            if ($records > 1) {
                $records = intdiv($records, 2);
            } elseif ($events > 1) {
                $events = intdiv($events, 2);
            } elseif (1 === $events && 1 === $records) {
                $records = 0;
            } else {
                break;
            }
        }

        return ['events' => [], 'pageViews' => [], 'json' => ''];
    }

    /**
     * Whether an envelope is inside both the decompressed and the wire caps.
     */
    private function fits(string $json): bool
    {
        // An empty envelope means encoding failed (see encode()). Sending it
        // would be silent data loss: the endpoint answers 202 to an empty body,
        // and this class would read that as acceptance and delete events it
        // never sent.
        return '' !== $json
            && \strlen($json) <= IngestTransportInterface::MAX_BODY_BYTES
            && $this->transport->wireSize($json) <= $this->transport->wireCap();
    }

    /**
     * Encode one flush envelope. camelCase throughout, per the contract.
     *
     * ``pageViews`` is only present when there are records to send, so an
     * install that does not count page views sends exactly the envelope it
     * always has.
     *
     * @param list<Event>                                                                    $events
     * @param list<array{id: string, day: string, path: string, bucket: string, count: int}> $pageViews
     */
    private function encode(array $events, int $dropped, array $pageViews): string
    {
        $envelope = [
            'producer' => 'symfony-bundle/'.GlobetrottersAiPresenceBundle::VERSION,
            // Local sampling is the escape hatch for a very high-traffic apex;
            // the backend scales counts by 1/sampleRate. This bundle reports
            // everything it captured, so it stays at 1.0.
            'sampleRate' => 1.0,
            'dropped' => $dropped,
            'events' => array_map(static fn (Event $event): array => $event->toPayload(), $events),
        ];
        if ([] !== $pageViews) {
            $envelope['pageViews'] = $pageViews;
        }

        $json = json_encode($envelope, Event::JSON_FLAGS);

        return \is_string($json) ? $json : '';
    }

    private function recordAttempt(IngestResult $result, string $lane, int $events, int $pageViews): void
    {
        $now = $this->now();
        $state = $this->state->state();

        $update = [
            'last_flush_attempt' => $now,
            'last_flush_lane' => $lane,
            'last_flush_error' => $result->isRateLimited()
                ? 'Globetrotters is rate limiting this install; the same batch will be retried.'
                : $result->errorMessage(),
        ];

        if ($result->isAccepted()) {
            $update['last_flush_ok'] = $now;
            $update['flush_count'] = (int) $state['flush_count'] + 1;
            $update['events_sent'] = (int) $state['events_sent'] + $events;
            $update['page_views_sent'] = (int) $state['page_views_sent'] + $pageViews;
        }

        $this->state->update($update);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
