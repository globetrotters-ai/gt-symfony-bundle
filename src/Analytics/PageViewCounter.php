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
 *   are replaced by atomic rename, so a reader never sees half of one. Each
 *   record also carries ``src``, a fingerprint of the open file it was sealed
 *   from, which makes sealing idempotent across a crash (see sealDay()). It
 *   stays on disk and is never sent.
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

    /**
     * Reserved key in an open file holding its generation: a random number
     * written when the file is created and kept for its life. It is what
     * identifies an open file when sealing (see {@see self::fingerprint()}) —
     * Linux reuses a freed inode at once and mtime is to the second, so a
     * late-view file recreated just after sealing can otherwise match the
     * sealed one on inode, mtime and bytes. No ``"bucket\tpath"`` key can
     * collide with it: it has no tab. (Not a NUL-prefixed key: the file is
     * written through an object cast, where PHP reads one as a private property
     * name and json_encode() drops it.).
     */
    public const GENERATION_KEY = '#gen';

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
                $contents = self::readAll($handle);
                $counts = self::decodeCounts($contents);
                $generation = self::generation($contents) ?? ([] === $counts ? random_int(1, \PHP_INT_MAX) : null);
                $key = self::keyFor($counts, $path, $bucket);
                $counts[$key] = ($counts[$key] ?? 0) + 1;

                return self::overwrite($handle, null === $generation ? $counts : [self::GENERATION_KEY => $generation] + $counts);
            });
            if (null !== $stored) {
                return $stored;
            }
        }

        return false;
    }

    /**
     * Drop every page-view file, open and sealed — what disabling the opt-in
     * means for counts already collected. Returns how many files went.
     */
    public function discardAll(): int
    {
        $removed = 0;
        foreach ([self::OPEN_FILE => 'pageviews-', self::SEALED_FILE => 'pageviews-sealed-'] as $pattern => $prefix) {
            foreach ($this->days($pattern) as $day) {
                if (@unlink($this->directory->path($prefix.$day.'.json'))) {
                    ++$removed;
                }
            }
        }

        return $removed;
    }

    /**
     * Identify one open file for sealing: its generation
     * ({@see self::GENERATION_KEY}), which a file recreated for the same day (a
     * late view after sealing) never shares with the one sealed before it,
     * whatever the filesystem does with inodes. The same file surviving a crash
     * keeps its generation, so it is recognised as already sealed.
     *
     * @param array<int|string, int> $stat as returned by stat()/fstat()
     */
    public static function fingerprint(array $stat, string $contents): string
    {
        $generation = self::generation($contents);
        if (null !== $generation) {
            return sha1('gen|'.$generation);
        }

        // A file written before generations existed: identified by inode,
        // mtime and bytes, which holds wherever inodes are not reused at once.
        return sha1(($stat['ino'] ?? 0).'|'.($stat['mtime'] ?? 0).'|'.$contents);
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
            // The stored form, so the records kept keep their fingerprint.
            $records = $this->storedRecords($day);
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
            $contents = self::readAll($handle);
            $stat = fstat($handle);
            $source = self::fingerprint(false === $stat ? [] : $stat, $contents);

            $records = [];
            foreach (self::decodeCounts($contents) as $key => $count) {
                [$bucket, $path] = explode("\t", $key, 2);
                $records[] = ['id' => Uuid::v4(), 'day' => $day, 'path' => $path, 'bucket' => $bucket, 'count' => $count, 'src' => $source];
            }

            if ([] !== $records) {
                $existing = $this->storedRecords($day);
                // Idempotence across a crash. If the process died after the
                // rename below but before the unlink, this very file (same
                // inode, mtime and bytes) is back here with its records already
                // sealed — re-sealing it under fresh UUIDs would make the
                // backend, which dedupes on id, count the day twice. So the
                // merge is skipped and only the unlink is finished.
                $alreadySealed = [] !== array_filter($existing, static fn (array $record): bool => ($record['src'] ?? null) === $source);
                if ($alreadySealed) {
                    $records = [];
                } elseif (!$this->replace($this->directory->path('pageviews-sealed-'.$day.'.json'), array_merge($existing, $records))) {
                    // Nothing sealed, nothing lost: the open file stays for the
                    // next run.
                    return 0;
                }
            }

            // Unlinked while the lock is still held, so an increment waiting on
            // it finds the file gone (see increment()) rather than writing into
            // a day that has already been read. A crash before this line is
            // caught by the fingerprint check above on the next run.
            @unlink($openPath);

            return \count($records);
        }) ?? 0;
    }

    /**
     * Sealed records in their wire form: ``src`` is local bookkeeping and is
     * never sent.
     *
     * @return list<array{id: string, day: string, path: string, bucket: string, count: int}>
     */
    private function sealedRecords(string $day): array
    {
        return array_map(
            static fn (array $record): array => [
                'id' => $record['id'],
                'day' => $record['day'],
                'path' => $record['path'],
                'bucket' => $record['bucket'],
                'count' => $record['count'],
            ],
            $this->storedRecords($day),
        );
    }

    /**
     * Sealed records as stored, with the ``src`` fingerprint when present.
     *
     * @return list<array{id: string, day: string, path: string, bucket: string, count: int, src?: string}>
     */
    private function storedRecords(string $day): array
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
                $stored = [
                    'id' => $record['id'],
                    'day' => $day,
                    'path' => $record['path'],
                    'bucket' => $record['bucket'],
                    'count' => $record['count'],
                ];
                if (\is_string($record['src'] ?? null)) {
                    $stored['src'] = $record['src'];
                }
                $records[] = $stored;
            }
        }

        return $records;
    }

    /**
     * Write a whole file by atomic rename, so a reader sees the old contents
     * or the new, never a truncated one.
     *
     * @param list<array{id: string, day: string, path: string, bucket: string, count: int, src?: string}> $records
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
     * @param array<string, int> $counts including the generation entry
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
     * The ``"bucket\tpath"`` counts only. The generation entry has no tab, so
     * it never reaches counts(), openViews(), the path cap or a sealed record;
     * {@see self::generation()} reads it.
     *
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

    /**
     * The open file's generation, or null when it has none.
     */
    private static function generation(string $contents): ?int
    {
        $decoded = json_decode($contents, true);
        $generation = \is_array($decoded) ? ($decoded[self::GENERATION_KEY] ?? null) : null;

        return \is_int($generation) ? $generation : null;
    }

    private static function isDay(string $day): bool
    {
        return 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $day);
    }
}
