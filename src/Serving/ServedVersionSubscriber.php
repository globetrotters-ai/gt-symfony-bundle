<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Analytics\ServedVersion;
use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records which bundle version the web lane served ({@see ServedVersion}), on
 * kernel.terminate so it costs the response nothing.
 *
 * On the requests that read the bundle anyway: a served artefact, and the
 * homepage, where the JSON-LD is injected. The version is the one this request
 * read, from the request's own pool, which is the point: it is what the web
 * actually serves, not what the last refresh pulled.
 */
final class ServedVersionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ArtefactCache $cache,
        private readonly Options $options,
        private readonly ServedVersion $servedVersion,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => ['onKernelTerminate', 0]];
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        try {
            $request = $event->getRequest();
            if (!$event->isMainRequest() || !\in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
                return;
            }
            if (!$request->attributes->has(Router::ATTRIBUTE_PATH) && $request->getPathInfo() !== $this->options->homepagePath()) {
                return;
            }

            $requestTime = $request->server->get('REQUEST_TIME');
            $this->servedVersion->observe($this->cache->version(), \is_int($requestTime) ? $requestTime : null);
        } catch (\Throwable) {
            // The response is already on the wire.
        }
    }
}
