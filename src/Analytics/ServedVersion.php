<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

use Globetrotters\AiPresenceBundle\Sync\Provenance;
use Symfony\Component\Clock\ClockInterface;

/**
 * The bundle version the web lane is serving, as last observed by a web
 * request: the heartbeat that lets ``gt:status`` and the backend see a refresh
 * that never reached the apex.
 *
 * In the buffer directory, not in the cache pool: it exists to catch a pool
 * the CLI and the web server do not share, and a heartbeat kept in that pool
 * would be invisible to exactly the process that needs to read it. The
 * directory is the one place every lane already shares across processes and
 * users. So, like the rest of the directory, it is written only while
 * reporting is configured; serving still never writes.
 *
 * Written on ``kernel.terminate`` (after the response is sent), only when the
 * version changed or the record is an hour old, so the common case is one
 * small read. An observation older than the stored one is ignored, so a
 * request that began before a refresh cannot overwrite what a later request
 * saw.
 *
 * Its version is ``captureContext.servedVersion`` in every flush envelope: the
 * version marker's id (``2026-10-18-050325``), or null when unknown.
 */
final class ServedVersion
{
    public const FILE = 'served-version.json';

    public const HEARTBEAT_SECONDS = 3600;

    public function __construct(
        private readonly BufferDirectory $directory,
        private readonly AnalyticsOptions $options,
        private readonly ClockInterface $clock,
        private readonly string $projectDir = '',
    ) {
    }

    /**
     * Note the version one web request served ('' for none). Never throws.
     */
    public function observe(string $version, ?int $seenAt = null): void
    {
        if (!$this->options->isConfigured()) {
            return;
        }

        try {
            $seenAt ??= $this->clock->now()->getTimestamp();
            $stored = $this->read();
            if (null !== $stored) {
                if ($seenAt < $stored['seen_at']) {
                    return;
                }
                if ($version === $stored['version']
                    && $this->projectDir === $stored['project_dir']
                    && $seenAt - $stored['seen_at'] < self::HEARTBEAT_SECONDS) {
                    return;
                }
            }
            if (!$this->directory->ensure()) {
                return;
            }

            $this->directory->replace(
                $this->directory->path(self::FILE),
                (string) json_encode(['version' => $version, 'seen_at' => $seenAt] + Provenance::describe($this->projectDir), \JSON_UNESCAPED_SLASHES),
            );
        } catch (\Throwable) {
            // Diagnostic only; the response is already on the wire.
        }
    }

    /**
     * @return array{version: string, seen_at: int, hostname: string, project_dir: string, user: string}|null
     */
    public function read(): ?array
    {
        $path = $this->directory->path(self::FILE);
        $json = is_file($path) ? @file_get_contents($path) : false;
        if (!\is_string($json) || '' === $json) {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!\is_array($decoded) || !\is_string($decoded['version'] ?? null) || !\is_int($decoded['seen_at'] ?? null)) {
            return null;
        }

        return [
            'version' => $decoded['version'],
            'seen_at' => $decoded['seen_at'],
            'hostname' => \is_string($decoded['hostname'] ?? null) ? $decoded['hostname'] : '',
            'project_dir' => \is_string($decoded['project_dir'] ?? null) ? $decoded['project_dir'] : '',
            'user' => \is_string($decoded['user'] ?? null) ? $decoded['user'] : '',
        ];
    }

    /**
     * The served version for the envelope, or null when none is known (no web
     * request observed yet, or the web serves no bundle).
     */
    public function version(): ?string
    {
        $version = $this->read()['version'] ?? '';

        return '' !== $version ? $version : null;
    }
}
