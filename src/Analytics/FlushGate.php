<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

use Symfony\Component\Clock\ClockInterface;

/**
 * Owns "may a flush run right now?" — the 15-minute interval and the
 * cross-process lock.
 *
 * Both live on the filesystem rather than in the cache pool because the callers
 * are separate processes: a cron'd console command, a Messenger worker, and any
 * number of PHP-FPM workers running the opportunistic ``kernel.terminate``
 * fallback. ``flock`` is the only mechanism all three share without adding a
 * dependency, and an mtime is a race-free stamp.
 *
 * Because every lane stamps the same file, whichever lane an install actually
 * has configured suppresses the others for the rest of the interval — the cron
 * lane and the fallback cannot both flush the same buffer.
 */
final class FlushGate
{
    public const LOCK_FILE = 'flush.lock';
    public const STAMP_FILE = 'flush.stamp';

    /**
     * The contract's cadence: at most one flush every 15 minutes. Comfortably
     * inside the backend's 90-minute staleness window, past which hits are
     * re-stamped to arrival time and land in the wrong buckets.
     */
    public const INTERVAL_SECONDS = 900;

    public function __construct(
        private readonly BufferDirectory $directory,
        private readonly ClockInterface $clock,
    ) {
    }

    public function isDue(): bool
    {
        $last = $this->lastAttemptAt();

        return null === $last
            || ($this->clock->now()->getTimestamp() - $last) >= self::INTERVAL_SECONDS;
    }

    /**
     * When a flush was last attempted by any lane, or null if never.
     */
    public function lastAttemptAt(): ?int
    {
        $path = $this->directory->path(self::STAMP_FILE);
        clearstatcache(true, $path);
        $mtime = @filemtime($path);

        return \is_int($mtime) ? $mtime : null;
    }

    /**
     * Record that a flush is being attempted now. The mtime is the whole
     * payload.
     */
    public function stamp(): void
    {
        if (!$this->directory->ensure()) {
            return;
        }

        @touch($this->directory->path(self::STAMP_FILE), $this->clock->now()->getTimestamp());
    }

    /**
     * Run $work while holding the exclusive flush lock, or return null when
     * another process already holds it.
     *
     * Non-blocking on purpose: a second flush arriving mid-flush should skip,
     * not queue up behind a 20-second HTTP call — least of all on
     * ``kernel.terminate``, where queueing would pin an FPM worker.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T|null
     */
    public function withLock(\Closure $work): mixed
    {
        if (!$this->directory->ensure()) {
            return null;
        }

        $handle = @fopen($this->directory->path(self::LOCK_FILE), 'c');
        if (false === $handle) {
            return null;
        }

        try {
            if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
                return null;
            }

            try {
                return $work();
            } finally {
                flock($handle, \LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
