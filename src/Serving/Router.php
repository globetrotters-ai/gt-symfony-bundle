<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Serves the cached apex artefacts from a kernel.request subscriber that runs
 * before routing (priority 64, ahead of RouterListener at 32 and the security
 * firewall at 8, but after ValidateRequestListener at 256), so the artefact
 * paths work even when a catch-all controller or another bundle would otherwise
 * claim them without bypassing Symfony's trusted-host/request validation.
 * Setting the event response stops propagation, the Symfony analogue of the WP
 * plugin's exit.
 *
 * A path miss or cold cache returns without touching the response, letting the
 * app handle the request normally.
 *
 * Alongside the artefact set it answers two paths that are deliberately not
 * {@see ContentTypes} entries, because neither is fetched: the generated
 * sitemap at ``/ai-sitemap.xml`` (rendered from the cache — putting it in the
 * map would make {@see \Globetrotters\AiPresenceBundle\Sync\ArtefactSync}
 * try to pull it), and the IndexNow key file at ``/<key>.txt``, whose name is
 * only known at runtime. See {@see Sitemap} and {@see IndexNowKey}.
 *
 * This is also the only point at which agent traffic to an apex install is
 * observable at all — the request terminates here and never touches a
 * Globetrotters edge — so served requests are marked for the server-log capture
 * that runs on kernel.terminate.
 */
final class Router implements EventSubscriberInterface
{
    public const PRIORITY = 64;

    /**
     * Request attributes marking a response this subscriber produced, read by
     * {@see ArtefactCaptureSubscriber}
     * and {@see ArtefactHeaderSubscriber}.
     */
    public const ATTRIBUTE_PATH = '_gt_artefact_path';
    public const ATTRIBUTE_BYTES = '_gt_artefact_bytes';

    /**
     * Marks a served IndexNow key response, read by
     * {@see ArtefactHeaderSubscriber}.
     *
     * Separate from {@see self::ATTRIBUTE_PATH} on purpose. That attribute is
     * what {@see ArtefactCaptureSubscriber} keys off, and serving the key is not
     * agent traffic: Presence Analytics counts agent fetches of the artefact
     * set, and a search engine reading the key to verify host control is
     * neither, so folding it in would inflate the numbers a customer reads as
     * demand for their presence. The key response still needs its no-store
     * headers re-asserted, which is what this attribute is for.
     */
    public const ATTRIBUTE_KEY = '_gt_indexnow_key';

    /**
     * Marks a served sitemap response, read by {@see ArtefactHeaderSubscriber}.
     *
     * Separate from {@see self::ATTRIBUTE_PATH} for the same reason as the key:
     * a search engine reading a sitemap to schedule a crawl is not an agent
     * fetching the presence, and folding it into Presence Analytics would
     * inflate the numbers a customer reads as demand for their presence. The
     * response still needs its no-store headers re-asserted.
     */
    public const ATTRIBUTE_SITEMAP = '_gt_sitemap';

    /**
     * The headers that make an artefact response measurable, re-asserted on
     * kernel.response by {@see ArtefactHeaderSubscriber}. Directives are
     * ksorted by ResponseHeaderBag; written in the served order so code, README
     * and tests all read alike.
     *
     * @var array<string, string>
     */
    public const NO_STORE_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        // `private` is not decoration: ResponseHeaderBag::computeCacheControlValue()
        // appends it to any Cache-Control that names neither public, private
        // nor s-maxage, so a bare `no-store` would be rewritten to exactly this
        // on the way out anyway. Spelling it out keeps the served header equal
        // to the header in this file — and it reinforces no-store rather than
        // weakening it.
        'Cache-Control' => 'no-store, private',
        'Surrogate-Control' => 'no-store',
    ];

    /**
     * Open CORS for the artefact surface, re-asserted alongside
     * {@see self::NO_STORE_HEADERS}.
     *
     * Every artefact is public, unauthenticated, read-only agent metadata: the
     * same bytes go to any anonymous GET, no credential is in play, and a
     * browser-context agent client cannot read a discovery document without
     * this. Kept out of NO_STORE_HEADERS because it has nothing to do with
     * cacheability and that constant's name should keep meaning what it says.
     *
     * Scoped to the artefact paths this router serves, never the rest of the
     * host application: the attribute check in ArtefactHeaderSubscriber is what
     * enforces that.
     *
     * `Access-Control-Allow-Methods` is deliberately absent — a simple
     * cross-origin GET is not preflighted, so nothing would ever read it.
     *
     * @var array<string, string>
     */
    public const CORS_HEADERS = [
        'Access-Control-Allow-Origin' => '*',
    ];

    public function __construct(
        private readonly ArtefactCache $cache,
        private readonly Options $options,
        private readonly Sitemap $sitemap,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', self::PRIORITY]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!\in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return;
        }

        $pathInfo = $request->getPathInfo();
        $path = ltrim($pathInfo, '/');
        // Only the canonical single-slash form serves an artefact; reject
        // non-canonical variants like //schema.json that ltrim would otherwise
        // collapse onto the same map entry, exposing a duplicate URL.
        if ('/'.$path !== $pathInfo) {
            return;
        }
        // The artefact set is matched first. The edge proxy declares its key
        // route *before* the artefact catch-all and relies on the key grammar
        // (8-128 chars) to keep ``/llms.txt`` reaching the artefact handler; the
        // order here reaches the same outcome structurally, so a served file can
        // never be shadowed by whatever a marker happens to carry.
        $type = ContentTypes::forPath($path);
        if (null === $type) {
            // The sitemap is matched before the key for the same structural
            // reason the artefact map is matched before both: ``ai-sitemap.xml``
            // is itself a well-formed key filename (a ten-character
            // ``[A-Za-z0-9-]`` stem), so an install whose key happened to be
            // ``ai-sitemap`` would otherwise shadow it.
            if (!$this->serveSitemap($event, $request, $path)) {
                $this->serveIndexNowKey($event, $request, $path);
            }

            return;
        }

        $body = $this->cache->get($path);
        if (null === $body) {
            return;
        }

        // The canonical path (with its leading slash) and the size of what we
        // served, so the capture listener doesn't have to re-derive either. The
        // guard above means this is always one of the six paths the backend
        // matches exactly — getPathInfo() has already dropped any query string,
        // so /llms.txt?v=2 reports as /llms.txt rather than being dropped at
        // ingest.
        $request->attributes->set(self::ATTRIBUTE_PATH, $pathInfo);
        $request->attributes->set(self::ATTRIBUTE_BYTES, \strlen($body));

        $event->setResponse(new Response($body, 200, self::headers($type)));
    }

    /**
     * Serve the generated sitemap, or return false so the caller keeps looking.
     *
     * Nothing is answered until at least one artefact is listable —
     * {@see Sitemap::render()} returns '' otherwise. A urlset naming only the
     * homepage says less than whatever the site already serves, and an install
     * that has never synced should look untouched.
     */
    private function serveSitemap(RequestEvent $event, Request $request, string $path): bool
    {
        if (Sitemap::PATH !== $path) {
            return false;
        }

        $body = $this->sitemap->render($request->getSchemeAndHttpHost());
        if ('' === $body) {
            return false;
        }

        $request->attributes->set(self::ATTRIBUTE_SITEMAP, true);
        $event->setResponse(new Response($body, 200, self::sitemapHeaders()));

        return true;
    }

    /**
     * Response headers for the generated sitemap.
     *
     * ``no-store`` for *freshness* rather than measurement: the document is
     * rendered from the cache and from this request's own host, so a shared TTL
     * could keep advertising a URL a later refresh dropped, or hand a host alias
     * a listing full of another host's URLs.
     *
     * No {@see self::CORS_HEADERS}, matching {@see self::keyHeaders()}: a
     * sitemap is fetched server-side by a crawler, so the cross-origin grant
     * stays scoped to the discovery documents that actually need it.
     *
     * @return array<string, string>
     */
    public static function sitemapHeaders(): array
    {
        return ['Content-Type' => Sitemap::CONTENT_TYPE] + self::NO_STORE_HEADERS;
    }

    /**
     * Serve the IndexNow key when this request asks for exactly this site's key
     * file; otherwise return and let the application answer, which is its
     * normal 404.
     *
     * IndexNow verifies control of a host by reading ``https://{host}/{key}.txt``
     * and comparing the body to the key. The apex is served by the integrator's
     * own stack, so nothing Globetrotters hosts can supply it here — without
     * this route a file-drop apex simply cannot be announced.
     *
     * Never a 200 with an empty body when no key is stored: that answers the
     * verification fetch with a file that fails it, which is worse than the
     * absence the 404 truthfully reports.
     */
    private function serveIndexNowKey(RequestEvent $event, Request $request, string $path): void
    {
        // Structural test first, so a normal page request never pays a state read.
        $candidate = IndexNowKey::candidateFromPath($path);
        if ('' === $candidate) {
            return;
        }

        $key = $this->options->indexNowKey();
        if ('' === $key || $candidate !== $key) {
            return;
        }

        $request->attributes->set(self::ATTRIBUTE_KEY, true);

        // The body is the key and nothing else — no trailing newline, matching
        // the edge proxy's serve_indexnow_key, which returns ``content=key``.
        $event->setResponse(new Response($key, 200, self::keyHeaders()));
    }

    /**
     * Response headers for the IndexNow key file.
     *
     * ``no-store`` on both headers for the artefacts' measurement reason and for
     * one of its own: the key is mutable state that reaches an install only on
     * its next refresh, so a cached copy of a rotated-away key fails
     * verification for as long as it lives.
     *
     * No {@see self::CORS_HEADERS}, deliberately. That grant exists because a
     * browser-context agent client cannot read a discovery document without it;
     * the key file is fetched server-side by a search engine and needs no such
     * grant. This is the apex of a site we do not own, so the grant stays scoped
     * to the paths that need it.
     *
     * That is a statement about what *this bundle* grants, not a guarantee that
     * the response reaches the client without CORS headers: an application that
     * stamps them on every response (NelmioCorsBundle over `^/`, say) still
     * does so here, and {@see ArtefactHeaderSubscriber} deliberately does not
     * strip them back off. Nothing is lost by that. The key is public by
     * construction — its entire job is to be readable off the host it verifies,
     * and it is committed in tfvars — so cross-origin readability discloses
     * nothing. Overriding an integrator's own CORS policy on their own domain
     * would be a real cost for no benefit, which is not the trade the
     * `Cache-Control` override makes: there a shared TTL breaks verification
     * and measurement outright.
     *
     * @return array<string, string>
     */
    public static function keyHeaders(): array
    {
        return ['Content-Type' => 'text/plain; charset=utf-8'] + self::NO_STORE_HEADERS;
    }

    /**
     * Response headers for a served artefact.
     *
     * ``no-store`` on both headers is a **precondition for measurement, not an
     * optimisation**. With a shared TTL in front of the origin the application
     * never executes for the duration of that TTL, and the reported hit count
     * is silently low by an amount that varies per customer and per POP — a
     * number wrong by an unknown amount is worse than no number.
     * ``Surrogate-Control`` is what Cloudflare, Varnish and Fastly honour;
     * ``Cache-Control: no-store`` covers browsers, agents and everything else.
     *
     * Symfony's own HttpCache consumes ``Surrogate-Control`` only when it
     * carries a ``content="…ESI/1.0…"`` capability token, so ``no-store``
     * passes an app running the reverse proxy untouched and reaches an upstream
     * CDN intact.
     *
     * @return array<string, string>
     */
    public static function headers(string $contentType): array
    {
        return ['Content-Type' => $contentType] + self::NO_STORE_HEADERS + self::CORS_HEADERS;
    }
}
