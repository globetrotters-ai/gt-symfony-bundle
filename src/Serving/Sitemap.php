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
            .$urls
            .'</urlset>'."\n";
    }

    /**
     * The `<url>` blocks for every listed path.
     */
    private function urlEntries(string $origin): string
    {
        $origin = rtrim($origin, '/');
        $lastModified = $this->lastModified();

        $urls = '';
        foreach ($this->paths() as $path) {
            $urls .= "  <url>\n    <loc>".htmlspecialchars($origin.$path, \ENT_XML1 | \ENT_QUOTES, 'UTF-8')."</loc>\n";
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
