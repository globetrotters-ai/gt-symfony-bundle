<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Sync;

use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;

/**
 * One refresh at a time, whichever lane starts it.
 *
 * A cron'd ``gt:refresh`` and a Scheduler worker can both be configured, and
 * two cron entries (or a deploy hook and cron) can fire together: each would
 * pull the full artefact set, and the second write would race the first. The
 * lock is an ``flock`` on a file in the shared buffer directory, as the flush's
 * is ({@see \Globetrotters\AiPresenceBundle\Analytics\FlushGate}), because the
 * lanes are separate processes and often separate OS users.
 *
 * Non-blocking: a second runner skips rather than queue behind a pull. When the
 * directory cannot hold a lock at all the refresh runs unlocked, as it did
 * before there was one — a refresh must never depend on the reporting lane's
 * directory being usable.
 *
 * Local to one host. Two servers that each run cron still pull twice, and need
 * the refresh scheduled on one of them.
 */
final class RefreshLock
{
    public const LOCK_FILE = 'refresh.lock';

    public function __construct(private readonly BufferDirectory $directory)
    {
    }

    /**
     * Run $work under the lock, or return null without running it when
     * another process holds it.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T|null
     */
    public function withLock(\Closure $work): mixed
    {
        $handle = $this->directory->ensure()
            ? $this->directory->openForLock($this->directory->path(self::LOCK_FILE), 'c')
            : false;
        if (false === $handle) {
            return $work();
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
