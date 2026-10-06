<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

/**
 * The one directory the reporting lane writes to: the event log, the drop
 * counter, the flush lock and the flush stamp.
 *
 * Kept as its own object so the three collaborators that need it share one
 * "can we write here at all?" answer, and so ``gt:status`` can report an
 * unwritable directory as a configuration problem rather than having it
 * surface as reporting that silently never happens.
 *
 * Serving never touches this. The bundle's promise that it needs no filesystem
 * write access still holds for an install that has not configured reporting.
 *
 * Shared between OS users: the web user writes it and whoever runs the flush
 * (a cron user, a worker) does too, and the two are often not the same
 * account. So it is created group-writable and setgid, so every file in it
 * takes the directory's group, and every file is made group-writable by the
 * process that creates it — explicitly, because the default umask of 022
 * would leave the other user able to read the files and nothing more. What
 * the bundle cannot do is put both users in one group; that is the host's
 * part.
 *
 * Nothing is world-readable: the event log holds client IPs.
 */
final class BufferDirectory
{
    /** Group-writable, and setgid so files inherit the directory's group. */
    public const DIR_MODE = 0o2770;

    public const FILE_MODE = 0o660;

    public function __construct(private readonly string $dir)
    {
    }

    public function path(string $file): string
    {
        return $this->dir.\DIRECTORY_SEPARATOR.$file;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * Create the directory if needed. False means reporting cannot store
     * anything — never an exception, because this is reached from the serve
     * path's terminate listener.
     */
    public function ensure(): bool
    {
        if (is_dir($this->dir)) {
            return is_writable($this->dir);
        }

        // Two workers can reach the mkdir at the same moment; the loser gets
        // false from mkdir() and a true from the is_dir() retry. The mode is
        // set again after creating, since mkdir()'s is masked by the umask.
        if (@mkdir($this->dir, 0o775, true)) {
            @chmod($this->dir, self::DIR_MODE);

            return true;
        }

        return is_dir($this->dir);
    }

    /**
     * Files in the directory this process could not rewrite in place, for the
     * status command: the web user and the flush's user each need write
     * access to the event log and the drop counter, and a file created under
     * an older release (or by hand) may grant it to its owner only.
     *
     * @return list<string> file names
     */
    public function unwritableFiles(): array
    {
        $names = [];
        foreach (glob($this->dir.\DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (is_file($path) && !is_writable($path)) {
                $names[] = basename($path);
            }
        }

        return $names;
    }

    /**
     * Give a file this process just created the shared mode. Only for files it
     * created: a mode the host later sets on an existing file is theirs.
     * Best effort and silent, as everything on this path is.
     */
    public function share(string $path): void
    {
        @chmod($path, self::FILE_MODE);
    }

    /**
     * Open a file to lock it, creating it with $mode when missing.
     *
     * Falls back to a read-only handle when the file exists but this user may
     * not write it — one created by another user before files were shared, or
     * under a host's own stricter setup. flock() needs no write access, so
     * the lock still holds: callers that only read through the handle and
     * replace the file by rename() (which needs write access on the directory,
     * not the file) keep working across users.
     *
     * @return resource|false
     */
    public function openForLock(string $path, string $mode): mixed
    {
        $existed = is_file($path);
        $handle = @fopen($path, $mode);
        if (false !== $handle) {
            if (!$existed) {
                $this->share($path);
            }

            return $handle;
        }

        return is_file($path) ? @fopen($path, 'r') : false;
    }
}
