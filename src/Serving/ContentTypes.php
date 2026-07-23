<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

/**
 * Canonical apex-path → Content-Type map.
 *
 * Mirrors the backend ``bundle_builder._FILE_CONTENT_TYPES`` and the
 * edge-proxy ``ALLOWED_FILES``/``WELL_KNOWN_FILES`` tables, restricted to the
 * minimal apex footprint. Heavy files (``llms-full.txt``, ``content.md``) are
 * intentionally absent — they are linked back to Globetrotters by absolute
 * URL rather than served locally.
 *
 * Paths are apex-relative, without a leading slash.
 */
final class ContentTypes
{
    public const VERSION_MARKER = '.well-known/globetrotters-apex-version.json';

    private const MAP = [
        'llms.txt' => 'text/plain; charset=utf-8',
        'ai.json' => 'application/json; charset=utf-8',
        'schema.json' => 'application/ld+json; charset=utf-8',
        '.well-known/mcp.json' => 'application/json; charset=utf-8',
        '.well-known/agent-card.json' => 'application/json; charset=utf-8',
        self::VERSION_MARKER => 'application/json; charset=utf-8',
    ];

    /**
     * @return list<string>
     */
    public static function paths(): array
    {
        return array_keys(self::MAP);
    }

    public static function forPath(string $path): ?string
    {
        return self::MAP[$path] ?? null;
    }

    public static function has(string $path): bool
    {
        return isset(self::MAP[$path]);
    }
}
