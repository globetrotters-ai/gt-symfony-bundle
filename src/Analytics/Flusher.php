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
    ) {
    }

    /**
     * Run one flush. Returns true when at least one batch was accepted.
     *
     * @param string $lane       which scheduling lane triggered this, for the status command
     * @param int    $maxBatches batches to drain before yielding
     */
    public function run(string $lane, int $maxBatches = self::MAX_BATCHES_PER_RUN): bool
    {
        if (!$this->options->isConfigured() || !$this->buffer->isUsable()) {
            return false;
        }

        $accepted = $this->gate->withLock(function () use ($lane, $maxBatches): bool {
            // Stamped up front, so a failing endpoint still holds every lane to
            // the 15-minute cadence instead of retrying on every request.
            $this->gate->stamp();

            try {
                $sent = false;
                for ($batch = 0; $batch < $maxBatches; ++$batch) {
                    if (!$this->flushOnce($lane)) {
                        break;
                    }
                    $sent = true;
                    if (0 === $this->buffer->count()) {
                        break;
                    }
                }

                return $sent;
            } catch (\Throwable $error) {
                // Same rule as capture: reporting degrades, the application
                // does not.
                $this->state->update([
                    'last_flush_attempt' => $this->now(),
                    'last_flush_error' => $error->getMessage(),
                    'last_flush_lane' => $lane,
                ]);

                return false;
            }
        });

        return true === $accepted;
    }

    /**
     * Claim, send and settle one batch.
     */
    private function flushOnce(string $lane): bool
    {
        $this->buffer->prune();

        $claimed = $this->buffer->claim(IngestTransportInterface::MAX_EVENTS_PER_BATCH);
        if ([] === $claimed) {
            $this->state->update(['last_flush_attempt' => $this->now(), 'last_flush_lane' => $lane]);

            return false;
        }

        $dropped = $this->buffer->droppedPending();
        $batch = $this->fit($claimed, $dropped);

        if ([] === $batch['events']) {
            // Nothing fits, not even one event. Drop the head rather than wedge
            // every later flush behind it — and count it, so the gap stays
            // measured.
            $this->buffer->discard([$claimed[0]->id()]);

            return false;
        }

        $result = $this->transport->post(
            $this->options->endpoint(),
            $this->options->ingestToken(),
            $batch['json'],
        );

        $this->recordAttempt($result, $lane, \count($batch['events']));

        if (!$result->isAccepted()) {
            // Leave every claimed event in place: the next attempt re-sends
            // this exact payload, same UUIDs, and the backend dedupes it.
            return false;
        }

        $this->buffer->release(array_map(static fn (Event $event): string => $event->id(), $batch['events']));
        $this->buffer->settleDropped($dropped);

        return true;
    }

    /**
     * Reduce a claim until its envelope fits under the backend's wire caps.
     *
     * Halves rather than trimming one event at a time: an oversize batch is
     * rare, and halving converges in a handful of encodes instead of hundreds.
     * Whatever is left over stays buffered for the next batch rather than being
     * discarded.
     *
     * @param list<Event> $claimed
     *
     * @return array{events: list<Event>, json: string}
     */
    private function fit(array $claimed, int $dropped): array
    {
        $count = \count($claimed);

        while ($count >= 1) {
            $slice = \array_slice($claimed, 0, $count);
            $json = $this->encode($slice, $dropped);

            if ($this->fits($json)) {
                return ['events' => $slice, 'json' => $json];
            }

            if (1 === $count) {
                break;
            }
            $count = (int) max(1, floor($count / 2));
        }

        return ['events' => [], 'json' => ''];
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
     * @param list<Event> $events
     */
    private function encode(array $events, int $dropped): string
    {
        $json = json_encode([
            'producer' => 'symfony-bundle/'.GlobetrottersAiPresenceBundle::VERSION,
            // Local sampling is the escape hatch for a very high-traffic apex;
            // the backend scales counts by 1/sampleRate. This bundle reports
            // everything it captured, so it stays at 1.0.
            'sampleRate' => 1.0,
            'dropped' => $dropped,
            'events' => array_map(static fn (Event $event): array => $event->toPayload(), $events),
        ], Event::JSON_FLAGS);

        return \is_string($json) ? $json : '';
    }

    private function recordAttempt(IngestResult $result, string $lane, int $events): void
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
        }

        $this->state->update($update);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
