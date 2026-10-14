<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

/**
 * Canonical apex-path → Content-Type map.
 *
 * Mirrors the backend ``bundle_builder._FILE_CONTENT_TYPES`` and the
 * edge-proxy ``ALLOWED_FILES``/``WELL_KNOWN_FILES`` tables, restricted to the
 * apex discovery set (``deploy_config_renderer.APEX_DISCOVERY_PATHS``) plus
 * the version marker. Every path here is a required artefact of a refresh
 * (see ``ArtefactSync::requiredPaths()``). Heavy files (``llms-full.txt``, ``content.md``) are
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
        // here takes those checks off the customer's list. Mirrors gt-backend
        // ``deploy_config_renderer.APEX_DISCOVERY_PATHS``.
        self::AI_CATALOG => 'application/json; charset=utf-8',
        '.well-known/agent-skills/index.json' => 'application/json; charset=utf-8',
        'agent.json' => 'application/json; charset=utf-8',
        'AGENTS.md' => 'text/markdown; charset=utf-8',
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
