<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;

/**
 * Renders this site's RFC 9727 API catalog, answered by {@see Router} at
 * ``/.well-known/api-catalog``: an RFC 9264 linkset whose single entry is
 * anchored on the homepage and points at the agent surface this install serves.
 *
 * **Generated locally, never fetched**, for the {@see Sitemap}'s reason: the
 * edge proxy renders the same document per host, so a
 * fetched copy would name the Globetrotters host. Built from the request's own
 * origin and from the paths the cache would actually serve, it cannot list a
 * local URL this install does not answer. The one remote URL is the ``item``
 * below, read from the MCP card as published.
 *
 * Same shape as the edge proxy's, minus ``/okf``, which no companion serves
 * locally. readiness' ``check_api_catalog`` scores a linkset entry carrying both
 * ``service-desc`` and ``service-doc`` at 100.
 *
 * Since 0.9.1 the entry also carries RFC 9727's ``item``: one link per API the
 * host exposes, which today is the MCP server named by the cached MCP card's
 * first remote. The key is left out when the card is not cached or names no
 * remote — an empty list would advertise an API surface that is not there.
 */
final class ApiCatalog
{
    public const PATH = '.well-known/api-catalog';

    /**
     * RFC 9727 §5: no charset parameter is defined for the linkset media type.
     */
    public const CONTENT_TYPE = 'application/linkset+json';

    /**
     * Apex path → [relation, target media type], in served order. Mirrors the
     * edge proxy's catalog.
     *
     * The catalog is answered exactly when one of these is cached.
     * {@see LinkHeader} and {@see Sitemap} apply that rule to the cache reads
     * they already make, so neither advertises a catalog that would 404.
     *
     * @var array<string, array{string, string}>
     */
    public const RELS = [
        'llms.txt' => ['service-doc', 'text/plain'],
        '.well-known/mcp.json' => ['service-desc', 'application/json'],
        '.well-known/agent-card.json' => ['service-desc', 'application/json'],
        ContentTypes::AI_CATALOG => ['service-desc', 'application/json'],
    ];

    public function __construct(
        private readonly ArtefactCache $cache,
    ) {
    }

    /**
     * The document, or '' when none of its targets is cached.
     *
     * ``$origin`` is the requesting client's own scheme and host, as for the
     * sitemap.
     */
    public function render(string $origin): string
    {
        $origin = rtrim($origin, '/');
        $entry = [];
        foreach (self::RELS as $path => [$rel, $type]) {
            if (null !== $this->cache->get($path)) {
                $entry[$rel][] = ['href' => $origin.'/'.$path, 'type' => $type];
            }
        }
        if ([] === $entry) {
            return '';
        }
        $mcpUrl = $this->mcpUrl();
        if (null !== $mcpUrl) {
            $entry['item'] = [['href' => $mcpUrl, 'type' => 'application/json']];
        }

        $linkset = ['linkset' => [['anchor' => $origin.'/'] + $entry]];

        return json_encode($linkset, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * ``remotes[0].url`` of the cached MCP card, or null.
     *
     * Read as published, never rewritten onto the request's origin: the MCP
     * server is reached where the card says it is, which is the Globetrotters
     * host — the one URL in this document that may name it.
     */
    private function mcpUrl(): ?string
    {
        $card = $this->cache->get('.well-known/mcp.json');
        if (null === $card) {
            return null;
        }
        $card = json_decode($card, true);
        $url = \is_array($card) ? $card['remotes'][0]['url'] ?? null : null;

        return \is_string($url) && '' !== $url ? $url : null;
    }
}
