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
 */
final class Router implements EventSubscriberInterface
{
    public const PRIORITY = 512;

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

        $path = ltrim($request->getPathInfo(), '/');
        $type = ContentTypes::forPath($path);
        if (null === $type) {
            return;
        }

        $body = $this->cache->get($path);
        if (null === $body) {
            return;
        }

        $event->setResponse(new Response($body, 200, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=300',
        ]));
    }
}
