<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

/**
 * Newline-delimited JSON buffer on the local filesystem.
 *
 * The contract forbids a single serialised blob because concurrent PHP-FPM
 * workers read-modify-write it and lose each other's events — and concurrent
 * served requests are the normal case here. A dedicated table is the
 * reference implementation's answer; this bundle has no database dependency
 * (and no Doctrine), so the equivalent property is bought with an append-only
 * log instead: one ``file_put_contents(FILE_APPEND | LOCK_EX)`` per event is a
 * single atomic append, never a read-modify-write, so writers cannot clobber
 * one another however many of them there are.
 *
 * The mutating operations (trim, delete) do read-modify-write the whole file,
 * but each one is a single pass under ``LOCK_EX`` on the same inode the
 * appenders lock, so an append that lands mid-rewrite simply waits and is then
 * written past the new end of file. They are also rare: a trim only runs on
 * overflow, a delete only after an accepted flush.
 *
 * A buffered line is byte-for-byte the event's wire representation, so the
 * file's size *is* the buffer's wire size and no per-event size estimate has
 * to be maintained.
 */
final class NdjsonEventStore implements EventStoreInterface
{
    public const FILE = 'events.ndjson';

    public function __construct(private readonly BufferDirectory $directory)
    {
    }

    public function append(Event $event): bool
    {
        $line = $event->toLine();
        if ('' === $line) {
            return false;
        }
        if (!$this->directory->ensure()) {
            return false;
        }

        return false !== @file_put_contents($this->path(), $line."\n", \FILE_APPEND | \LOCK_EX);
    }

    public function sizeBytes(): int
    {
        $path = $this->path();
        clearstatcache(true, $path);
        $size = @filesize($path);

        return \is_int($size) ? $size : 0;
    }

    public function count(): int
    {
        return \count($this->read());
    }

    public function trim(int $maxEvents, int $maxBytes): int
    {
        return $this->rewrite(static function (array $lines) use ($maxEvents, $maxBytes): array {
            // A line that no longer decodes can never be flushed (the flusher
            // skips it) nor deleted by id (it has none), so it would sit at the
            // head of the buffer forever. Overflow handling is the one place
            // that can honestly remove it — and it is a lost event either way,
            // so it counts towards `dropped` like any other.
            $kept = array_values(array_filter(
                $lines,
                static fn (string $line): bool => \is_array(json_decode($line, true)),
            ));

            if (\count($kept) > $maxEvents) {
                $kept = \array_slice($kept, \count($kept) - $maxEvents);
            }

            $bytes = self::bytesOf($kept);
            $total = \count($kept);
            $drop = 0;
            while ($drop < $total && $bytes > $maxBytes) {
                $bytes -= \strlen($kept[$drop]) + 1;
                ++$drop;
            }

            return $drop > 0 ? \array_slice($kept, $drop) : $kept;
        });
    }

    public function oldest(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $events = [];
        foreach ($this->read() as $line) {
            $decoded = json_decode($line, true);
            if (!\is_array($decoded)) {
                continue;
            }
            $events[] = Event::fromArray($decoded);
            if (\count($events) >= $limit) {
                break;
            }
        }

        return $events;
    }

    public function delete(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        $set = array_flip($ids);

        return $this->rewrite(static fn (array $lines): array => array_values(array_filter(
            $lines,
            static function (string $line) use ($set): bool {
                $decoded = json_decode($line, true);
                if (!\is_array($decoded) || !isset($decoded['id']) || !\is_scalar($decoded['id'])) {
                    // Left in place deliberately: trim() owns removing
                    // undecodable lines, and counts them as dropped when it
                    // does. Silently discarding them here would lose events
                    // without recording that they were lost.
                    return true;
                }

                return !isset($set[(string) $decoded['id']]);
            },
        )));
    }

    public function isUsable(): bool
    {
        return $this->directory->ensure();
    }

    public function path(): string
    {
        return $this->directory->path(self::FILE);
    }

    /**
     * Read-modify-write the whole log under an exclusive lock.
     *
     * @param \Closure(list<string>): list<string> $mutate
     *
     * @return int lines removed
     */
    private function rewrite(\Closure $mutate): int
    {
        $path = $this->path();
        if (!is_file($path)) {
            return 0;
        }

        // 'c+' rather than 'r+' so the handle is writable without truncating on
        // open — the truncate has to happen *after* the lock is held.
        $handle = @fopen($path, 'c+');
        if (false === $handle) {
            return 0;
        }

        try {
            if (!flock($handle, \LOCK_EX)) {
                return 0;
            }

            $contents = stream_get_contents($handle);
            $lines = self::split(\is_string($contents) ? $contents : '');
            $kept = $mutate($lines);
            $removed = \count($lines) - \count($kept);
            if ($removed <= 0) {
                return 0;
            }

            rewind($handle);
            ftruncate($handle, 0);
            if ([] !== $kept) {
                fwrite($handle, implode("\n", $kept)."\n");
            }
            fflush($handle);
            clearstatcache(true, $path);

            return $removed;
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return list<string>
     */
    private function read(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }

        $handle = @fopen($path, 'r');
        if (false === $handle) {
            return [];
        }

        try {
            if (!flock($handle, \LOCK_SH)) {
                return [];
            }
            $contents = stream_get_contents($handle);

            return self::split(\is_string($contents) ? $contents : '');
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param list<string> $lines
     */
    private static function bytesOf(array $lines): int
    {
        $bytes = 0;
        foreach ($lines as $line) {
            $bytes += \strlen($line) + 1;
        }

        return $bytes;
    }

    /**
     * @return list<string>
     */
    private static function split(string $contents): array
    {
        if ('' === $contents) {
            return [];
        }

        return array_values(array_filter(
            explode("\n", $contents),
            static fn (string $line): bool => '' !== trim($line),
        ));
    }
}
