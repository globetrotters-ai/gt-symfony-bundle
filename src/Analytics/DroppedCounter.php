<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

/**
 * Events lost to buffer overflow — pending (not yet reported) and lifetime.
 *
 * "Incompleteness must be measured, not invisible": a gap in the numbers is
 * otherwise indistinguishable from an absence of traffic, which is the exact
 * failure mode server-log capture exists to eliminate. The pending count ships
 * in the next envelope's ``dropped`` field and is only cleared once that
 * envelope is accepted.
 *
 * Kept on the filesystem next to the buffer rather than in the cache pool
 * because every mutation is a read-modify-write and the writers are concurrent
 * FPM workers. An exclusive lock over the counter file makes the increment
 * atomic; a cache item would lose increments in precisely the overflow burst
 * that produced them.
 */
final class DroppedCounter
{
    public const FILE = 'dropped.json';

    public function __construct(private readonly BufferDirectory $directory)
    {
    }

    public function add(int $count): void
    {
        if ($count < 1) {
            return;
        }

        $this->mutate(static fn (array $state): array => [
            'pending' => $state['pending'] + $count,
            'total' => $state['total'] + $count,
        ]);
    }

    /**
     * Clear the drops carried by an accepted envelope.
     *
     * Subtracts rather than zeroes: a trim may have incremented the counter
     * while the flush was in flight, and those drops still need reporting.
     */
    public function settle(int $shipped): void
    {
        if ($shipped < 1) {
            return;
        }

        $this->mutate(static fn (array $state): array => [
            'pending' => max(0, $state['pending'] - $shipped),
            'total' => $state['total'],
        ]);
    }

    public function pending(): int
    {
        return $this->read()['pending'];
    }

    public function total(): int
    {
        return $this->read()['total'];
    }

    /**
     * @return array{pending: int, total: int}
     */
    public function read(): array
    {
        $path = $this->directory->path(self::FILE);
        if (!is_file($path)) {
            return ['pending' => 0, 'total' => 0];
        }

        $handle = @fopen($path, 'r');
        if (false === $handle) {
            return ['pending' => 0, 'total' => 0];
        }

        try {
            if (!flock($handle, \LOCK_SH)) {
                return ['pending' => 0, 'total' => 0];
            }
            $contents = stream_get_contents($handle);

            return self::decode(\is_string($contents) ? $contents : '');
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param \Closure(array{pending: int, total: int}): array{pending: int, total: int} $update
     */
    private function mutate(\Closure $update): void
    {
        if (!$this->directory->ensure()) {
            return;
        }

        $handle = @fopen($this->directory->path(self::FILE), 'c+');
        if (false === $handle) {
            return;
        }

        try {
            if (!flock($handle, \LOCK_EX)) {
                return;
            }
            $contents = stream_get_contents($handle);
            $state = $update(self::decode(\is_string($contents) ? $contents : ''));

            $json = json_encode($state);
            if (!\is_string($json)) {
                return;
            }

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $json);
            fflush($handle);
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array{pending: int, total: int}
     */
    private static function decode(string $contents): array
    {
        $decoded = json_decode($contents, true);
        if (!\is_array($decoded)) {
            return ['pending' => 0, 'total' => 0];
        }

        return [
            'pending' => max(0, (int) ($decoded['pending'] ?? 0)),
            'total' => max(0, (int) ($decoded['total'] ?? 0)),
        ];
    }
}
