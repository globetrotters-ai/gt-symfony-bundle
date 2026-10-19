<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Cache;

use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * The bundle's own pool (``globetrotters_ai_presence.cache``): a filesystem
 * pool every process of the application reads, whichever OS user it runs as.
 *
 * The refresh is a CLI or worker process and the serving is PHP-FPM, so the
 * artefacts only reach the apex through a pool both see. ``cache.app`` is not
 * one: an application may map it to APCu, which is memory private to one
 * process (to one php-fpm pool, and to each CLI run), and it lives under
 * ``var/cache``, which ``cache:clear`` and every deploy empty.
 *
 * It lives beside the reporting buffer and is shared the same way
 * ({@see \Globetrotters\AiPresenceBundle\Analytics\BufferDirectory}): the web
 * user and the cron user are often two accounts in one group, so every
 * directory it creates is group-writable and setgid and every file it writes
 * is group-readable and -writable, whatever the process umask. Symfony's
 * adapter creates files with the umask applied, so writes run under a umask
 * that keeps the group bits and drops the world's — except on a thread-safe
 * (ZTS) build, where the umask is process-wide and a worker thread serving a
 * request must not see it change. A threaded server (FrankenPHP) runs every
 * request as one user, so the cron user then needs to be that same user.
 *
 * Not tagged ``cache.pool``: ``cache:clear`` must not empty it, and the
 * namespace must not change with the container build.
 */
final class SharedFilesystemAdapter extends FilesystemAdapter
{
    public const DIR_MODE = 0o2770;

    private const UMASK = 0o007;

    public function __construct(string $directory)
    {
        self::ensureDirectory($directory);

        $this->shared(function () use ($directory): void {
            parent::__construct('', 0, $directory);
        });
    }

    public function commit(): bool
    {
        return $this->shared(fn (): bool => parent::commit());
    }

    /**
     * save() goes through commit() in every supported symfony/cache release;
     * wrapped too so the modes never hinge on that.
     */
    public function save(CacheItemInterface $item): bool
    {
        return $this->shared(fn (): bool => parent::save($item));
    }

    /**
     * Create the pool directory, and any missing parent, as the reporting
     * buffer is created: setgid and group-writable, so the files in it take
     * the directory's group. Only a directory this call creates is given the
     * mode; one the host prepared is theirs. Silent: a web process that may
     * not create it simply reads nothing, and serving never writes.
     */
    private static function ensureDirectory(string $directory): void
    {
        if ('' === $directory || is_dir($directory)) {
            return;
        }

        self::ensureDirectory(\dirname($directory));
        // Two processes can race here; the loser's mkdir() fails and the
        // directory is the winner's to set.
        if (@mkdir($directory, 0o770)) {
            @chmod($directory, self::DIR_MODE);
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function shared(\Closure $work): mixed
    {
        if (\PHP_ZTS) {
            return $work();
        }

        $previous = umask(self::UMASK);
        try {
            return $work();
        } finally {
            umask($previous);
        }
    }
}
