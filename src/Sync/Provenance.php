<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Sync;

/**
 * Which process this is, as far as sharing the cache pool goes: the host, the
 * application checkout and the OS user it runs as.
 *
 * Recorded on both sides of the pool — by the refresh that pulled a bundle and
 * by the web lane that served one — so ``gt:status`` can show which of the
 * three differs when the two disagree: another server, a cron entry still
 * pointing at an old release, or a user that cannot read the pool.
 */
final class Provenance
{
    /**
     * @return array{hostname: string, project_dir: string, user: string}
     */
    public static function describe(string $projectDir): array
    {
        return [
            'hostname' => (string) gethostname(),
            'project_dir' => $projectDir,
            'user' => self::user(),
        ];
    }

    /**
     * The effective user, not the script owner get_current_user() reports.
     */
    public static function user(): string
    {
        if (\function_exists('posix_geteuid') && \function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid(posix_geteuid());
            if (\is_array($entry) && '' !== $entry['name']) {
                return $entry['name'];
            }
        }

        $env = getenv('USER');

        return \is_string($env) && '' !== $env ? $env : get_current_user();
    }

    /**
     * "user@host (project dir)", for the status command.
     *
     * @param array<string, mixed> $record
     */
    public static function label(array $record): string
    {
        $user = \is_string($record['user'] ?? null) ? $record['user'] : '';
        $host = \is_string($record['hostname'] ?? null) ? $record['hostname'] : '';
        $dir = \is_string($record['project_dir'] ?? null) ? $record['project_dir'] : '';

        return trim(\sprintf('%s@%s%s', '' !== $user ? $user : '?', '' !== $host ? $host : '?', '' !== $dir ? ' ('.$dir.')' : ''));
    }
}
