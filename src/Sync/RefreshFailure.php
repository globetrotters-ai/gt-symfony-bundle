<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Sync;

use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;

/**
 * The last failed refresh, kept in the buffer directory rather than the pool.
 *
 * The most important failure is the pool refusing the write (a directory the
 * cron user cannot write, a backend that is down), and a record in that same
 * pool is exactly what cannot be stored then: ``gt:status`` showed "Last error
 * —" for a refresh that failed every hour. The directory is the one place
 * every lane shares; the refresh lock already lives there.
 *
 * Cleared by the next successful pull. Never throws: a refresh's outcome does
 * not depend on its diagnostics.
 */
final class RefreshFailure
{
    public const FILE = 'refresh-error.json';

    public function __construct(
        private readonly BufferDirectory $directory,
        private readonly string $projectDir = '',
        private readonly string $environment = '',
    ) {
    }

    public function record(string $message, string $version, int $at): void
    {
        try {
            if (!$this->directory->ensure()) {
                return;
            }
            $this->directory->replace(
                $this->directory->path(self::FILE),
                (string) json_encode(
                    ['at' => $at, 'message' => $message, 'version' => $version] + Provenance::describe($this->projectDir, $this->environment),
                    \JSON_UNESCAPED_SLASHES,
                ),
            );
        } catch (\Throwable) {
        }
    }

    public function clear(): void
    {
        $path = $this->directory->path(self::FILE);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return array{at: int, message: string, version: string, hostname: string, project_dir: string, user: string, env: string}|null
     */
    public function read(): ?array
    {
        $path = $this->directory->path(self::FILE);
        $json = is_file($path) ? @file_get_contents($path) : false;
        $decoded = \is_string($json) ? json_decode($json, true) : null;
        if (!\is_array($decoded) || !\is_int($decoded['at'] ?? null) || !\is_string($decoded['message'] ?? null)) {
            return null;
        }

        return [
            'at' => $decoded['at'],
            'message' => $decoded['message'],
            'version' => \is_string($decoded['version'] ?? null) ? $decoded['version'] : '',
        ] + Provenance::fromRecord($decoded);
    }
}
