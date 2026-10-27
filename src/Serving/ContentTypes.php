<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

/**
 * Canonical apex-path → Content-Type map.
 *
 * Mirrors the content types the Globetrotters bundle builder and edge proxy
 * serve, restricted to the backend's apex discovery set plus the version
 * marker. Every path but the marker is a required artefact of a
 * refresh (see ``ArtefactSync::requiredPaths()``); the marker is fetched on
 * its own and synthesized when missing (``resolveVersionMarker()``). Heavy files (``llms-full.txt``, ``content.md``) are
 * intentionally absent — they are linked back to Globetrotters by absolute
 * URL rather than served locally.
 *
 * Paths are apex-relative, without a leading slash.
 */
final class ContentTypes
{
    public const VERSION_MARKER = '.well-known/globetrotters-apex-version.json';

    public const AI_CATALOG = '.well-known/ai-catalog.json';

    private const MAP = [
        'llms.txt' => 'text/plain; charset=utf-8',
        'ai.json' => 'application/json; charset=utf-8',
        'schema.json' => 'application/ld+json; charset=utf-8',
        '.well-known/mcp.json' => 'application/json; charset=utf-8',
        '.well-known/agent-card.json' => 'application/json; charset=utf-8',
        // Since 0.7.0: the ARD catalog, the Agent Skills index and the root
        // agent specs. readiness scores ``agent_spec_coverage`` on any two of
        // ``agent.json`` / ``AGENTS.md`` / …, and ``ai_catalog`` /
        // ``agent_skills_index`` on their well-known paths, so serving them
        // here takes those checks off the customer's list. Mirrors the
        // backend's apex discovery set.
        self::AI_CATALOG => 'application/json; charset=utf-8',
        '.well-known/agent-skills/index.json' => 'application/json; charset=utf-8',
        'agent.json' => 'application/json; charset=utf-8',
        'AGENTS.md' => 'text/markdown; charset=utf-8',
        self::VERSION_MARKER => 'application/json; charset=utf-8',
    ];

    /**
     * Alias path → the artefact it serves, byte for byte, under the artefact's
     * own Content-Type. Since 0.9.1.
     *
     * Agent discovery scanners probe ``/agents.md`` and the bare
     * ``/.well-known/agent-skills`` directory, not the published names, and a
     * redirect is a miss to some of them. Kept out of {@see self::MAP} so
     * nothing pulls, lists or links an alias: the refresh, the sitemap, the
     * ``Link`` header and the API catalog all name the canonical path only. A
     * hit is reported under the path actually requested; the server-log
     * ingest maps each alias to its artefact's format.
     */
    private const ALIASES = [
        'agents.md' => 'AGENTS.md',
        '.well-known/agent-skills' => '.well-known/agent-skills/index.json',
        '.well-known/agent-skills/' => '.well-known/agent-skills/index.json',
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

    /**
     * The artefact an alias serves, or the path itself.
     */
    public static function resolve(string $path): string
    {
        return self::ALIASES[$path] ?? $path;
    }
}
