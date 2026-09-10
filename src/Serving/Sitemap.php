<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Settings\Options;

/**
 * Renders this site's ``/sitemap.xml`` from what the bundle actually serves.
 *
 * **Generated locally, not fetched.** Globetrotters publishes a per-tenant
 * ``sitemap.xml`` alongside the artefacts, but every URL in it is in the *GT
 * host* URL space, and this bundle serves every artefact **verbatim** — the
 * sync fetches bytes and caches them, and there is no origin-rewriting
 * machinery anywhere in the repo. Serving the published file here would
 * advertise ``ai.<their-domain>`` URLs from the apex; rewriting it would mean
 * building a rewriter for exactly one file.
 *
 * Generating instead makes the listing self-consistent by construction: the
 * URL space is the incoming request's own, and a path is listed only if it is
 * in the cache this very request would serve it from. That is the same failure
 * the backend's ``offloaded_paths`` strip exists to prevent
 * (``apex.services.manifest_renderer.render_sitemap_xml``), closed structurally
 * rather than by keeping two lists in step.
 *
 * The XML shape mirrors that renderer, so an apex served by the file-drop lane
 * and one served by this bundle produce the same document.
 */
final class Sitemap
{
    public const PATH = 'sitemap.xml';

    public const CONTENT_TYPE = 'application/xml; charset=utf-8';

    /**
     * Marks a document this bundle has written entries into, so decorating is
     * idempotent. An XML comment inside `<urlset>` is valid and ignored by
     * every sitemap consumer.
     */
    public const MARKER = '  <!-- Globetrotters AI Presence -->';

    /**
     * Above this, an application's sitemap is left alone. sitemaps.org allows
     * 50 MB and 50 000 URLs; a document that large is a generated index of a
     * big site, where rewriting the body on every request costs more than the
     * six entries are worth — and it is the shape most likely to be streamed
     * or served from disk anyway.
     */
    private const MAX_DECORATE_BYTES = 1048576;

    public function __construct(
        private readonly Options $options,
        private readonly ArtefactCache $cache,
    ) {
    }

    /**
     * The root-relative paths to list, leading slash included.
     *
     * The homepage first — it is the presence's landing page, the one URL the
     * host is certain to answer, and the page this bundle injects JSON-LD into
     * — then every cached artefact, in {@see ContentTypes} order.
     *
     * Two deliberate omissions:
     *
     * - **The version marker.** Sync machinery, not content; the backend's own
     *   apex sitemap does not list it either.
     * - **Anything not in the cache.** A sitemap may only carry URLs the host
     *   actually answers, and an artefact absent from the cache falls through
     *   to the application's 404.
     *
     * The heavy files (``llms-full.txt``, ``content.md``) never appear because
     * this bundle never serves them — they stay linked back to Globetrotters by
     * absolute URL, and a sitemap may only carry same-host URLs.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = ['/'.ltrim($this->options->homepagePath(), '/')];
        foreach (ContentTypes::paths() as $path) {
            if (ContentTypes::VERSION_MARKER === $path) {
                continue;
            }
            if (null !== $this->cache->get($path)) {
                $paths[] = '/'.$path;
            }
        }

        return $paths;
    }

    /**
     * The document, or '' when there is nothing this install could honestly
     * list.
     *
     * ``$origin`` is the requesting client's own scheme and host, which is what
     * makes the URL space right without any configuration: the bundle serves
     * the apex it is installed at, whatever that is called today.
     */
    public function render(string $origin): string
    {
        $urls = $this->urlEntries($origin);
        if ('' === $urls) {
            return '';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .self::MARKER."\n"
            .$urls
            .'</urlset>'."\n";
    }

    /**
     * Fold this bundle's `<url>` entries into an application's own sitemap,
     * or null when this document must not be touched.
     *
     * **Why merge rather than stand aside.** The readiness validator's
     * ``sitemap_includes_llms`` probe fetches a hardcoded
     * ``{apex}/sitemap.xml`` and fails unless a ``<loc>`` names ``llms.txt`` or
     * ``llms-full.txt`` — it never reads the ``Sitemap:`` line, so a second
     * sitemap at a path of our own would be invisible to it. The backend tells
     * integrators doing this by hand exactly the same thing: *"merge these
     * ``<url>`` entries into your existing ``sitemap.xml``"*
     * (``deploy_config_renderer``). An install that leaves the app's sitemap
     * alone leaves the discovery surface out of the only sitemap anything
     * looks at.
     *
     * Additive and conservative: entries are inserted before the closing tag,
     * nothing is removed or rewritten, a ``<loc>`` the document already
     * carries is skipped, and the marker makes it idempotent.
     *
     * Returns null — leave it exactly as it is — for anything that is not a
     * plain, complete, reasonably sized ``<urlset>``: a ``<sitemapindex>``
     * (whose children we cannot reach), a body already carrying the marker, or
     * one above {@see self::MAX_DECORATE_BYTES}. A `public/sitemap.xml` never
     * reaches the kernel at all, the same documented limitation robots has.
     */
    public function decorate(string $xml, string $origin): ?string
    {
        if (\strlen($xml) > self::MAX_DECORATE_BYTES || str_contains($xml, self::MARKER)) {
            return null;
        }

        // A <sitemapindex> has no </urlset>, and its children are other
        // documents this response does not contain.
        $close = strripos($xml, '</urlset>');
        if (false === $close || !preg_match('/<urlset[\s>]/i', $xml)) {
            return null;
        }

        $urls = $this->urlEntries($origin, skipLocsPresentIn: $xml);
        if ('' === $urls) {
            return null;
        }

        return substr($xml, 0, $close).self::MARKER."\n".$urls.substr($xml, $close);
    }

    /**
     * The `<url>` blocks for every listed path, or '' when there are none left
     * to add.
     *
     * ``$skipLocsPresentIn`` drops any URL the target document already lists,
     * so decorating never duplicates an entry the application published
     * itself — and if it already lists all of them, there is nothing to do.
     */
    private function urlEntries(string $origin, string $skipLocsPresentIn = ''): string
    {
        $origin = rtrim($origin, '/');
        $lastModified = $this->lastModified();

        $urls = '';
        foreach ($this->paths() as $path) {
            $loc = htmlspecialchars($origin.$path, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
            if ('' !== $skipLocsPresentIn && str_contains($skipLocsPresentIn, '<loc>'.$loc.'</loc>')) {
                continue;
            }
            $urls .= "  <url>\n    <loc>".$loc."</loc>\n";
            if ('' !== $lastModified) {
                $urls .= '    <lastmod>'.$lastModified."</lastmod>\n";
            }
            $urls .= "  </url>\n";
        }

        return $urls;
    }

    /**
     * The date the served content last actually changed, as ``YYYY-MM-DD``, or
     * '' when this install has never completed a refresh.
     *
     * Data-derived, never wall clock, and deliberately *not* the last refresh:
     * refreshes run daily whether or not anything changed, so stamping every
     * URL with the refresh date would claim a freshness the content does not
     * have — a lie crawlers learn to discount. ``content_changed_at`` moves
     * only when the content hash does.
     *
     * Installs that predate that state key fall back to ``last_refresh``, which
     * is the best they can say: one overstated stamp until the next content
     * change is better than no ``<lastmod>`` at all, which the readiness
     * sitemap probe reads as a missing freshness signal.
     */
    private function lastModified(): string
    {
        $state = $this->options->state();
        $timestamp = (int) $state['content_changed_at'];
        if ($timestamp <= 0) {
            $timestamp = (int) $state['last_refresh'];
        }
        if ($timestamp <= 0) {
            return '';
        }

        return gmdate('Y-m-d', $timestamp);
    }
}
