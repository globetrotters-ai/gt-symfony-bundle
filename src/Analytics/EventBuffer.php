<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

/**
 * The bounded local buffer: append events, keep it inside the contract's
 * bounds, and account for what overflow costs.
 *
 * Bounded at 5000 events or 512KB, whichever comes first. On overflow the
 * **oldest** events go and the loss is counted, because a gap in the numbers
 * is otherwise indistinguishable from an absence of traffic.
 */
final class EventBuffer
{
    public const MAX_EVENTS = 5000;
    public const MAX_BYTES = 524288;

    public function __construct(
        private readonly EventStoreInterface $store,
        private readonly DroppedCounter $dropped,
    ) {
    }

    /**
     * Buffer one event, trimming when it pushes the log over the bound.
     */
    public function append(Event $event): bool
    {
        if (!$this->store->append($event)) {
            return false;
        }

        // One stat() per capture, and only the byte bound is consulted here:
        // a buffered line is structurally at least ~147 bytes (a 36-char UUID,
        // a 20-char timestamp, one of six fixed paths, plus the JSON keys), so
        // 5000 events cannot fit inside 512KB and the byte bound always trips
        // first. trim() still enforces both, for the case this reasoning is
        // wrong.
        if ($this->store->sizeBytes() > self::MAX_BYTES) {
            $this->prune();
        }

        return true;
    }

    /**
     * Enforce both bounds. Returns how many events were dropped.
     */
    public function prune(): int
    {
        $dropped = $this->store->trim(self::MAX_EVENTS, self::MAX_BYTES);
        if ($dropped > 0) {
            $this->dropped->add($dropped);
        }

        return $dropped;
    }

    /**
     * The oldest buffered events, in insertion order.
     *
     * @return list<Event>
     */
    public function claim(int $limit): array
    {
        return $this->store->oldest($limit);
    }

    /**
     * Remove events the ingest endpoint has accepted.
     *
     * @param list<string> $ids
     */
    public function release(array $ids): int
    {
        return $this->store->delete($ids);
    }

    /**
     * Remove events that cannot be sent, counting them as dropped.
     *
     * The escape hatch for an event that no batch size can fit under the wire
     * cap. Clipped fields make that all but impossible, but without it such an
     * event would sit at the head of the buffer and block every later flush.
     *
     * @param list<string> $ids
     */
    public function discard(array $ids): void
    {
        $removed = $this->store->delete($ids);
        if ($removed > 0) {
            $this->dropped->add($removed);
        }
    }

    public function count(): int
    {
        return $this->store->count();
    }

    public function sizeBytes(): int
    {
        return $this->store->sizeBytes();
    }

    public function droppedPending(): int
    {
        return $this->dropped->pending();
    }

    public function droppedTotal(): int
    {
        return $this->dropped->total();
    }

    public function settleDropped(int $shipped): void
    {
        $this->dropped->settle($shipped);
    }

    public function isUsable(): bool
    {
        return $this->store->isUsable();
    }
}
