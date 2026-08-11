<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Serves the cached apex artefacts from a kernel.request subscriber that runs
 * before routing (priority 512, ahead of RouterListener at 32 and the security
 * firewall at 8), so the artefact paths work even when a catch-all controller
 * or another bundle would otherwise claim them. Setting the event response
 * stops propagation, the Symfony analogue of the WP plugin's exit.
 *
 * A path miss or cold cache returns without touching the response, letting the
 * app handle the request normally.
 *
 * This is also the only point at which agent traffic to an apex install is
 * observable at all — the request terminates here and never touches a
 * Globetrotters edge — so served requests are marked for the server-log capture
 * that runs on kernel.terminate.
 */
final class Router implements EventSubscriberInterface
{
    public const PRIORITY = 512;

    /**
     * Request attributes marking a response this subscriber produced, read by
     * {@see ArtefactCaptureSubscriber}
     * and {@see ArtefactHeaderSubscriber}.
     */
    public const ATTRIBUTE_PATH = '_gt_artefact_path';
    public const ATTRIBUTE_BYTES = '_gt_artefact_bytes';

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

    public function __construct(private readonly ArtefactCache $cache)
    {
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
        $type = ContentTypes::forPath($path);
        if (null === $type) {
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
        return ['Content-Type' => $contentType] + self::NO_STORE_HEADERS;
    }
}
