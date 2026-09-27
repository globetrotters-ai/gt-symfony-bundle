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
 * - Counts only a **same-origin** beacon: the ``Origin`` host, or failing
 *   that the ``Referer`` host, must equal the request's own. Anything else is
 *   answered and discarded.
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
 * Off (the request is not intercepted at all) unless
 * ``reporting.page_views.enabled`` is set and reporting is configured.
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
        if (!$this->options->isActive()) {
            return;
        }

        try {
            $this->count($request);
        } catch (\Throwable) {
            // Reporting degrades; the response does not.
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
     * ``Origin`` decides when present (a browser sends it on every
     * ``sendBeacon`` POST); ``Referer`` is the fallback for one that does not.
     * Hosts are compared, not ports or schemes: a site answering on both
     * http and https is one site.
     */
    private static function isSameOrigin(Request $request): bool
    {
        $source = $request->headers->get('Origin') ?? $request->headers->get('Referer');
        if (null === $source || '' === $source) {
            return false;
        }

        $host = parse_url($source, \PHP_URL_HOST);

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
