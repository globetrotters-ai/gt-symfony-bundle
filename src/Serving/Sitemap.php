<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Settings\Options;

/**
 * Renders this site's AI-presence sitemap from what the bundle actually serves,
 * answered by {@see Router} at ``/ai-sitemap.xml``.
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
 * **``/ai-sitemap.xml``, deliberately not ``/sitemap.xml``.** On the GT-hosted
 * subdomain the edge proxy owns the whole host, so ``/sitemap.xml`` is free. A
 * customer's apex is not ours: most already serve one, whether from a sitemap
 * bundle, a static file, or a redirect onto an SEO plugin's index. Answering
 * that path from a pre-routing subscriber would shadow the site's real sitemap
 * with a six-URL artefact listing, and the cost is measurable rather than
 * theoretical — the readiness ``check_sitemap`` rubric scores a 5-9 URL sitemap
 * at 60 against 80-100 for a populated one, so shadowing would *lower* the
 * customer's public score. A distinct name collides with nothing and needs no
 * yield heuristic, because robots.txt takes a *list* of ``Sitemap:``
 * directives: ours is additive to whatever the site already declares. It also
 * says what the file is — a sitemap of the AI presence surfaces, not of the
 * site's content.
 *
 * The path diverges from the edge proxy's ``/sitemap.xml`` on purpose: they are
 * different objects (a whole-host sitemap vs an apex delta) on hosts with
 * different owners. ``gt-wordpress-plugin`` diverges identically.
 */
final class Sitemap
{
    public const PATH = 'ai-sitemap.xml';

    public const CONTENT_TYPE = 'application/xml; charset=utf-8';

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
        return [$this->homepagePath(), ...$this->artefactPaths()];
    }

    /**
     * The document, or '' when no artefact is listable.
     *
     * The gate is what the listing holds, not whether the cache holds anything:
     * with only the version marker left (a pool that evicted individual items,
     * say) the listing would name nothing but the homepage, which says less
     * than whatever the site already serves. Matches ``gt-wordpress-plugin``.
     *
     * ``$origin`` is the requesting client's own scheme and host, which is what
     * makes the URL space right without any configuration: the bundle serves
     * the apex it is installed at, whatever that is called today.
     */
    public function render(string $origin): string
    {
        $artefacts = $this->artefactPaths();
        if ([] === $artefacts) {
            return '';
        }

        $origin = rtrim($origin, '/');
        $lastModified = $this->lastModified();

        // The homepage is listed unstamped. It is the customer's own page,
        // edited independently of the bundle, so stamping it with the date the
        // artefacts last moved would tell crawlers a page rewritten today was
        // last modified whenever the presence last changed — the signal search
        // engines use to decide against a recrawl. The backend restricts its own
        // stamp the same way (``_render_sitemap_xml``'s ``lastmod_locs``).
        $urls = self::urlXml($origin.$this->homepagePath(), '');
        foreach ($artefacts as $path) {
            $urls .= self::urlXml($origin.$path, $lastModified);
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .$urls
            .'</urlset>'."\n";
    }

    private function homepagePath(): string
    {
        return '/'.ltrim($this->options->homepagePath(), '/');
    }

    /**
     * @return list<string>
     */
    private function artefactPaths(): array
    {
        $paths = [];
        foreach (ContentTypes::paths() as $path) {
            if (ContentTypes::VERSION_MARKER !== $path && null !== $this->cache->get($path)) {
                $paths[] = '/'.$path;
            }
        }

        return $paths;
    }

    private static function urlXml(string $loc, string $lastModified): string
    {
        $xml = "  <url>\n    <loc>".htmlspecialchars($loc, \ENT_XML1 | \ENT_QUOTES, 'UTF-8')."</loc>\n";
        if ('' !== $lastModified) {
            $xml .= '    <lastmod>'.$lastModified."</lastmod>\n";
        }

        return $xml."  </url>\n";
    }

    /**
     * The date the served content last actually changed, as ``YYYY-MM-DD``, or
     * '' when this install has not seen a change since the value existed.
     *
     * Data-derived, never wall clock, and never ``last_refresh``: refreshes run
     * daily whether or not anything changed, so a refresh date claims a
     * freshness the content does not have. ``content_changed_at`` moves only
     * when the content hash does. ``0`` omits ``<lastmod>``, which is valid,
     * and resolves itself on the next real change. Nothing is lost by the
     * omission: ``check_sitemap`` only credits ``<lastmod>`` on a sitemap of
     * ten or more URLs, and this one never lists more than six.
     */
    private function lastModified(): string
    {
        $timestamp = (int) $this->options->state()['content_changed_at'];

        return $timestamp > 0 ? gmdate('Y-m-d', $timestamp) : '';
    }
}
