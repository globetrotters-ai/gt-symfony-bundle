<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Sync;

/**
 * Which process this is, as far as sharing the cache pool goes: the host, the
 * application checkout, the kernel environment and the OS user it runs as.
 *
 * Recorded on both sides of the pool — by the refresh that pulled a bundle and
 * by the web lane that served one — so ``gt:status`` can show which of them
 * differs when the two disagree: another server, a cron entry still pointing
 * at an old release, a cron on ``prod`` while the web runs ``dev`` (each has
 * its own ``var/cache/<env>``), or a user that cannot read the pool.
 */
final class Provenance
{
    /**
     * @return array{hostname: string, project_dir: string, user: string, env: string}
     */
    public static function describe(string $projectDir, string $environment = ''): array
    {
        return [
            'hostname' => (string) gethostname(),
            'project_dir' => $projectDir,
            'user' => self::user(),
            'env' => $environment,
        ];
    }

    /**
     * The provenance fields of a stored record, each '' when missing.
     *
     * @param array<mixed> $record
     *
     * @return array{hostname: string, project_dir: string, user: string, env: string}
     */
    public static function fromRecord(array $record): array
    {
        $field = static fn (string $key): string => \is_string($record[$key] ?? null) ? $record[$key] : '';

        return [
            'hostname' => $field('hostname'),
            'project_dir' => $field('project_dir'),
            'user' => $field('user'),
            'env' => $field('env'),
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
     * "user@host, env prod (project dir)", for the status command.
     *
     * @param array<string, mixed> $record
     */
    public static function label(array $record): string
    {
        ['hostname' => $host, 'project_dir' => $dir, 'user' => $user, 'env' => $env] = self::fromRecord($record);

        return \sprintf(
            '%s@%s%s%s',
            '' !== $user ? $user : '?',
            '' !== $host ? $host : '?',
            '' !== $env ? ', env '.$env : '',
            '' !== $dir ? ' ('.$dir.')' : '',
        );
    }
}
