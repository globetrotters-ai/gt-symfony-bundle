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
 */
final class BufferDirectory
{
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
        // false from mkdir() and a true from the is_dir() retry.
        return @mkdir($this->dir, 0o775, true) || is_dir($this->dir);
    }
}
