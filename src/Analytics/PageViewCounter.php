<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

/**
 * First-party page-view counts per UTC day, path and bucket, on the local
 * filesystem next to the event buffer.
 *
 * Two kinds of file, one per day:
 *
 * - ``pageviews-<day>.json`` — the **open** day, a map of ``"bucket\tpath"``
 *   to a count, read-modify-written under ``flock(LOCK_EX)`` on every
 *   increment, the same discipline as {@see DroppedCounter}. Concurrent
 *   PHP-FPM workers are the normal case, and an exclusive lock over the file
 *   is what keeps an increment from being lost.
 * - ``pageviews-sealed-<day>.json`` — a **closed** day, a list of records each
 *   carrying a UUID assigned once, when the day is sealed. The backend dedupes
 *   on that id, so a retried flush re-sends the same ids and cannot double
 *   count. Only the flusher touches sealed files, under the flush lock; they
 *   are replaced by atomic rename, so a reader never sees half of one.
 *
 * Nothing that identifies a visitor ever reaches this class: no IP, no
 * User-Agent string, no cookie. A day, a path, a bucket and a count.
 *
 * Every method degrades instead of throwing. The increment is reached from a
 * request listener, and an unusable directory must cost the page view, never
 * the response.
 */
final class PageViewCounter
{
    /**
     * Distinct paths a day may count; a new path past this goes to
     * {@see PageViewRules::OTHER_PATH}. Bounds the file an increment rewrites,
     * and matches the backend's per-batch record cap.
     */
    public const MAX_PATHS_PER_DAY = 2000;

    /** The contract's cap on ``pageViews`` records per envelope. */
    public const MAX_RECORDS_PER_BATCH = 2000;

    /**
     * The backend accepts yesterday back to seven days ago (UTC, at arrival)
     * and drops anything older, so an older day is deleted, not sent.
     */
    public const RETENTION_DAYS = 7;

    private const OPEN_FILE = '/^pageviews-(\d{4}-\d{2}-\d{2})\.json$/';
    private const SEALED_FILE = '/^pageviews-sealed-(\d{4}-\d{2}-\d{2})\.json$/';

    private const JSON_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    public function __construct(private readonly BufferDirectory $directory)
    {
    }

    /**
     * Count one view. False when it could not be stored.
     */
    public function increment(string $day, string $path, string $bucket): bool
    {
        if (!self::isDay($day) || !$this->directory->ensure()) {
            return false;
        }

        // Two attempts: the file opened first may be the one the flusher seals
        // and unlinks while this worker waits for its lock. The second open
        // starts a fresh file for the day, which the next flush seals as a
        // late addition.
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $stored = $this->withExclusiveLock($this->directory->path('pageviews-'.$day.'.json'), static function ($handle) use ($path, $bucket): bool {
                $counts = self::decodeCounts(self::readAll($handle));
                $key = self::keyFor($counts, $path, $bucket);
                $counts[$key] = ($counts[$key] ?? 0) + 1;

                return self::overwrite($handle, $counts);
            });
            if (null !== $stored) {
                return $stored;
            }
        }

        return false;
    }

    /**
     * The open counts for one day, keyed ``"bucket\tpath"``.
     *
     * @return array<string, int>
     */
    public function counts(string $day): array
    {
        if (!self::isDay($day)) {
            return [];
        }

        return self::decodeCounts($this->readShared($this->directory->path('pageviews-'.$day.'.json')));
    }

    /**
     * Turn every open day before $today into sealed records. Returns how many
     * records were sealed.
     *
     * A day is final once it has ended, so each record is identified exactly
     * once, here. Views that arrive for a day after it was sealed (a request
     * that read the clock just before midnight) are sealed on the next run as
     * extra records; the ones already sealed keep their ids.
     */
    public function seal(string $today): int
    {
        if (!self::isDay($today)) {
            return 0;
        }

        $sealed = 0;
        foreach ($this->days(self::OPEN_FILE) as $day) {
            if ($day < $today) {
                $sealed += $this->sealDay($day);
            }
        }

        return $sealed;
    }

    /**
     * Delete open and sealed days the backend would drop on arrival. Returns
     * how many files were removed.
     */
    public function prune(string $today): int
    {
        if (!self::isDay($today)) {
            return 0;
        }

        $cutoff = (new \DateTimeImmutable($today, new \DateTimeZone('UTC')))
            ->modify('-'.self::RETENTION_DAYS.' days')
            ->format('Y-m-d');

        $removed = 0;
        foreach ([self::OPEN_FILE => 'pageviews-', self::SEALED_FILE => 'pageviews-sealed-'] as $pattern => $prefix) {
            foreach ($this->days($pattern) as $day) {
                if ($day < $cutoff && @unlink($this->directory->path($prefix.$day.'.json'))) {
                    ++$removed;
                }
            }
        }

        return $removed;
    }

    /**
     * Sealed records, oldest day first.
     *
     * @return list<array{id: string, day: string, path: string, bucket: string, count: int}>
     */
    public function pending(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $pending = [];
        foreach ($this->days(self::SEALED_FILE) as $day) {
            foreach ($this->sealedRecords($day) as $record) {
                $pending[] = $record;
                if (\count($pending) >= $limit) {
                    return $pending;
                }
            }
        }

        return $pending;
    }

    /**
     * Remove records the ingest endpoint accepted. Returns how many went.
     *
     * @param list<string> $ids
     */
    public function remove(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        $set = array_flip($ids);
        $removed = 0;
        foreach ($this->days(self::SEALED_FILE) as $day) {
            $records = $this->sealedRecords($day);
            $kept = array_values(array_filter($records, static fn (array $record): bool => !isset($set[$record['id']])));
            $gone = \count($records) - \count($kept);
            if (0 === $gone) {
                continue;
            }

            $path = $this->directory->path('pageviews-sealed-'.$day.'.json');
            if ([] === $kept ? @unlink($path) : $this->replace($path, $kept)) {
                $removed += $gone;
            }
        }

        return $removed;
    }

    /**
     * Whether a flush would have page views to send: a sealed record waiting,
     * or an open day that has ended.
     */
    public function hasReportable(string $today): bool
    {
        if ([] !== $this->days(self::SEALED_FILE)) {
            return true;
        }
        foreach ($this->days(self::OPEN_FILE) as $day) {
            if ($day < $today) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sealed records waiting to be sent, for the status command.
     */
    public function pendingRecords(): int
    {
        $total = 0;
        foreach ($this->days(self::SEALED_FILE) as $day) {
            $total += \count($this->sealedRecords($day));
        }

        return $total;
    }

    /**
     * Views counted on days not yet sealed, for the status command.
     */
    public function openViews(): int
    {
        $total = 0;
        foreach ($this->days(self::OPEN_FILE) as $day) {
            $total += array_sum($this->counts($day));
        }

        return $total;
    }

    private function sealDay(string $day): int
    {
        $openPath = $this->directory->path('pageviews-'.$day.'.json');

        return $this->withExclusiveLock($openPath, function ($handle) use ($day, $openPath): int {
            $records = [];
            foreach (self::decodeCounts(self::readAll($handle)) as $key => $count) {
                [$bucket, $path] = explode("\t", $key, 2);
                $records[] = ['id' => Uuid::v4(), 'day' => $day, 'path' => $path, 'bucket' => $bucket, 'count' => $count];
            }

            if ([] !== $records) {
                $sealedPath = $this->directory->path('pageviews-sealed-'.$day.'.json');
                if (!$this->replace($sealedPath, array_merge($this->sealedRecords($day), $records))) {
                    // Nothing sealed, nothing lost: the open file stays for the
                    // next run.
                    return 0;
                }
            }

            // Unlinked while the lock is still held, so an increment waiting on
            // it finds the file gone (see increment()) rather than writing into
            // a day that has already been read. A crash between the rename
            // above and this line would seal the day twice; that window is two
            // syscalls wide and taken only once a day.
            @unlink($openPath);

            return \count($records);
        }) ?? 0;
    }

    /**
     * @return list<array{id: string, day: string, path: string, bucket: string, count: int}>
     */
    private function sealedRecords(string $day): array
    {
        $decoded = json_decode($this->readShared($this->directory->path('pageviews-sealed-'.$day.'.json')), true);
        if (!\is_array($decoded)) {
            return [];
        }

        $records = [];
        foreach ($decoded as $record) {
            if (\is_array($record)
                && \is_string($record['id'] ?? null)
                && \is_string($record['path'] ?? null)
                && \is_string($record['bucket'] ?? null)
                && \is_int($record['count'] ?? null)
                && $record['count'] > 0
            ) {
                $records[] = [
                    'id' => $record['id'],
                    'day' => $day,
                    'path' => $record['path'],
                    'bucket' => $record['bucket'],
                    'count' => $record['count'],
                ];
            }
        }

        return $records;
    }

    /**
     * Write a whole file by atomic rename, so a reader sees the old contents
     * or the new, never a truncated one.
     *
     * @param list<array{id: string, day: string, path: string, bucket: string, count: int}> $records
     */
    private function replace(string $path, array $records): bool
    {
        $json = json_encode($records, self::JSON_FLAGS);
        if (!\is_string($json)) {
            return false;
        }

        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (false === @file_put_contents($temporary, $json)) {
            return false;
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    /**
     * The key an increment lands on: the path's own, or ``(other)`` once the
     * day counts {@see self::MAX_PATHS_PER_DAY} distinct paths and this is a
     * new one. The cap is on paths, not keys, so a counted path keeps its
     * name in a bucket it had not been seen in.
     *
     * @param array<string, int> $counts
     */
    private static function keyFor(array $counts, string $path, string $bucket): string
    {
        $key = $bucket."\t".$path;
        if (isset($counts[$key]) || PageViewRules::OTHER_PATH === $path) {
            return $key;
        }

        $paths = [];
        foreach (array_keys($counts) as $existing) {
            $paths[substr($existing, (int) strpos($existing, "\t") + 1)] = true;
        }
        if (isset($paths[$path])) {
            return $key;
        }
        unset($paths[PageViewRules::OTHER_PATH]);

        return \count($paths) >= self::MAX_PATHS_PER_DAY
            ? $bucket."\t".PageViewRules::OTHER_PATH
            : $key;
    }

    /**
     * Run $work on a file opened ``c+`` and held under an exclusive lock, or
     * return null when it cannot be opened or locked, or was unlinked while
     * this process waited for the lock.
     *
     * @template T
     *
     * @param \Closure(resource): T $work
     *
     * @return T|null
     */
    private function withExclusiveLock(string $path, \Closure $work): mixed
    {
        // 'c+' so the handle is writable without truncating on open — the
        // truncate has to happen after the lock is held.
        $handle = @fopen($path, 'c+');
        if (false === $handle) {
            return null;
        }

        try {
            if (!flock($handle, \LOCK_EX)) {
                return null;
            }

            try {
                $stat = fstat($handle);
                if (false === $stat || 0 === $stat['nlink']) {
                    return null;
                }

                return $work($handle);
            } finally {
                flock($handle, \LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    private function readShared(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            return '';
        }

        try {
            if (!flock($handle, \LOCK_SH)) {
                return '';
            }

            try {
                return self::readAll($handle);
            } finally {
                flock($handle, \LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Days present on disk for one kind of file, oldest first.
     *
     * @return list<string>
     */
    private function days(string $pattern): array
    {
        $entries = is_dir($this->directory->dir()) ? @scandir($this->directory->dir()) : false;
        if (false === $entries) {
            return [];
        }

        $days = [];
        foreach ($entries as $entry) {
            if (1 === preg_match($pattern, $entry, $match)) {
                $days[] = $match[1];
            }
        }
        sort($days);

        return $days;
    }

    /**
     * @param resource $handle
     */
    private static function readAll($handle): string
    {
        rewind($handle);
        $contents = stream_get_contents($handle);

        return \is_string($contents) ? $contents : '';
    }

    /**
     * @param resource           $handle
     * @param array<string, int> $counts
     */
    private static function overwrite($handle, array $counts): bool
    {
        $json = json_encode((object) $counts, self::JSON_FLAGS);
        if (!\is_string($json)) {
            return false;
        }

        rewind($handle);
        ftruncate($handle, 0);
        $written = fwrite($handle, $json);
        fflush($handle);

        return \strlen($json) === $written;
    }

    /**
     * @return array<string, int>
     */
    private static function decodeCounts(string $contents): array
    {
        $decoded = json_decode($contents, true);
        if (!\is_array($decoded)) {
            return [];
        }

        $counts = [];
        foreach ($decoded as $key => $count) {
            if (\is_string($key) && str_contains($key, "\t") && \is_int($count) && $count > 0) {
                $counts[$key] = $count;
            }
        }

        return $counts;
    }

    private static function isDay(string $day): bool
    {
        return 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $day);
    }
}
