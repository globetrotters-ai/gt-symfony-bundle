<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Analytics\PageViewCounter;
use Globetrotters\AiPresenceBundle\Analytics\PageViewOptions;
use Globetrotters\AiPresenceBundle\Analytics\PageViewRules;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers the page-view beacon: ``POST /.well-known/globetrotters/pv``.
 *
 * A sibling of {@see Router} rather than a branch inside it, at the same
 * priority and for the same reasons (ahead of routing and the security
 * firewall, behind request validation). Keeping it separate keeps the artefact
 * surface GET/HEAD-only by construction.
 *
 * What it does, and — as much the point — what it does not:
 *
 * - Counts only a **same-origin** beacon. A real ``Origin`` decides by its
 *   host. ``Origin: null`` — what a ``Referrer-Policy: no-referrer`` page
 *   sends — counts as absent, and ``Sec-Fetch-Site`` decides next
 *   (``same-origin`` only); without either, the ``Referer`` host. Anything
 *   else is answered and discarded.
 * - Reads a body of at most 1 KB, ``{"p": "<path>"}``, and counts the path
 *   only if the backend would keep it ({@see PageViewRules::normalizePath()}).
 * - Matches the User-Agent against two coarse patterns and keeps the bucket,
 *   never the string. An obvious bot is not counted at all.
 * - **Never reads a client IP**, never stores the User-Agent, never sets a
 *   cookie. It answers before the firewall, so no session is started, and
 *   {@see ArtefactHeaderSubscriber} strips any cookie the application adds.
 *
 * Always ``204`` with the no-store headers, counted or not: a beacon's
 * response is never read, and answering the same way either way tells a
 * spammer nothing.
 *
 * Counting is off unless ``reporting.page_views.enabled`` is set and
 * reporting is configured — but the path is **answered** either way, for as
 * long as the bundle is installed: pages cached while the counter was on keep
 * beaconing after it is switched off, and each of those would otherwise run
 * through routing and the firewall to the application's 404 or 405. Only an
 * active counter reads the body or writes anything.
 * Writing the counter is reporting, not serving: it goes to
 * ``reporting.buffer_dir`` like the event buffer, and an unwritable directory
 * costs the view, never the response.
 */
final class PageViewEndpoint implements EventSubscriberInterface
{
    public const MAX_BODY_BYTES = 1024;

    public function __construct(
        private readonly PageViewOptions $options,
        private readonly PageViewCounter $counter,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', Router::PRIORITY]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if ('POST' !== $request->getMethod() || PageViewBeacon::PATH !== $request->getPathInfo()) {
            return;
        }
        if ($this->options->isActive()) {
            try {
                $this->count($request);
            } catch (\Throwable) {
                // Reporting degrades; the response does not.
            }
        }

        $request->attributes->set(Router::ATTRIBUTE_PAGE_VIEW, true);
        $event->setResponse(new Response('', Response::HTTP_NO_CONTENT, Router::NO_STORE_HEADERS));
    }

    private function count(Request $request): void
    {
        if (!self::isSameOrigin($request)) {
            return;
        }

        $bucket = PageViewRules::bucket((string) $request->headers->get('User-Agent', ''));
        if (null === $bucket) {
            return;
        }

        $path = self::path($request);
        if (null === $path) {
            return;
        }

        $day = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        $this->counter->increment($day, $path, $bucket);
    }

    /**
     * Whether the beacon came from a page on this host.
     *
     * In order: a real ``Origin`` host; else ``Sec-Fetch-Site`` (only
     * ``same-origin`` passes); else the ``Referer`` host. Hosts are compared,
     * not ports or schemes: a site answering on both http and https is one
     * site.
     */
    private static function isSameOrigin(Request $request): bool
    {
        // 1. A real Origin decides. "null" is an opaque origin, not an answer:
        //    it is what a no-referrer page's beacon carries.
        $origin = trim((string) $request->headers->get('Origin', ''));
        if ('' !== $origin && 'null' !== strtolower($origin)) {
            return self::isSameHost($origin, $request);
        }

        // 2. Fetch metadata, set by the browser and not by page script.
        $site = trim((string) $request->headers->get('Sec-Fetch-Site', ''));
        if ('' !== $site) {
            return 'same-origin' === strtolower($site);
        }

        // 3. The Referer, for a browser that sends neither.
        $referer = trim((string) $request->headers->get('Referer', ''));

        return '' !== $referer && self::isSameHost($referer, $request);
    }

    private static function isSameHost(string $url, Request $request): bool
    {
        $host = parse_url($url, \PHP_URL_HOST);

        return \is_string($host) && '' !== $host && strtolower($host) === strtolower($request->getHost());
    }

    private static function path(Request $request): ?string
    {
        $length = $request->headers->get('Content-Length');
        if (null !== $length && (int) $length > self::MAX_BODY_BYTES) {
            return null;
        }

        // Read one byte past the cap rather than the whole body, so an
        // oversize POST without a Content-Length is refused without buffering it.
        $stream = $request->getContent(true);
        $body = stream_get_contents($stream, self::MAX_BODY_BYTES + 1);
        if (!\is_string($body) || '' === $body || \strlen($body) > self::MAX_BODY_BYTES) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!\is_array($decoded) || !\is_string($decoded['p'] ?? null)) {
            return null;
        }

        return PageViewRules::normalizePath($decoded['p']);
    }
}
