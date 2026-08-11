<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

/**
 * Append-only, FIFO storage for buffered capture events.
 *
 * Extracted from the storage mechanics so bound enforcement
 * ({@see EventBuffer}) and flush behaviour ({@see Flusher}) — the parts with
 * interesting logic — are unit-testable against an in-memory double, and so a
 * Doctrine-backed store can be added later without touching either.
 *
 * Events are addressed by their own UUID rather than a row ordinal: the front
 * of the buffer is trimmed on overflow, which shifts every ordinal behind it,
 * and a flush that deletes by stale ordinal would drop the wrong events.
 */
interface EventStoreInterface
{
    /**
     * Append one event. Returns false when the write failed.
     *
     * Implementations must append in a single atomic operation: concurrent
     * served requests are the normal case for this buffer, and a
     * read-modify-write loses events under exactly the traffic this feature
     * exists to measure.
     */
    public function append(Event $event): bool;

    /**
     * Bytes currently buffered. Must be cheap — it is consulted on every
     * capture to decide whether a trim is due.
     */
    public function sizeBytes(): int;

    /**
     * Exact number of buffered events.
     */
    public function count(): int;

    /**
     * Enforce both bounds in one pass, dropping oldest first.
     *
     * Returns how many events were dropped, for the ``dropped`` counter the
     * next envelope ships.
     */
    public function trim(int $maxEvents, int $maxBytes): int;

    /**
     * The oldest buffered events, in insertion order.
     *
     * @return list<Event>
     */
    public function oldest(int $limit): array;

    /**
     * Remove the given event ids. Returns how many were removed.
     *
     * @param list<string> $ids
     */
    public function delete(array $ids): int;

    /**
     * Whether the store can be written to at all. A read-only or missing
     * buffer directory degrades reporting; it never breaks serving.
     */
    public function isUsable(): bool;
}
