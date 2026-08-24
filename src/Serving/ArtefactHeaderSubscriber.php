<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Re-asserts the no-store headers on an artefact response, last.
 *
 * {@see Router} already sets them, but a shared TTL in front of the origin is
 * the one failure mode that makes the hit count silently wrong, so the header
 * must be unconditional rather than merely initial. An integrating app can
 * perfectly reasonably run a kernel.response listener — or a `#[Cache]`
 * attribute, `setPublic()`, `setSharedMaxAge()` — that stamps caching
 * directives over every response it sees. Running at the very end of the
 * dispatch chain makes the no-store path authoritative against all of them.
 *
 * Scoped to responses this bundle produced, via the attributes Router sets;
 * everything else in the application is left exactly as it was.
 */
final class ArtefactHeaderSubscriber implements EventSubscriberInterface
{
    /**
     * Low enough to run after any listener an application is likely to
     * register, including Symfony's own ResponseListener (0) and
     * SurrogateListener (0).
     */
    public const PRIORITY = -1024;

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', self::PRIORITY]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        if (!$event->getRequest()->attributes->has(Router::ATTRIBUTE_PATH)) {
            return;
        }

        $headers = $event->getResponse()->headers;
        foreach (Router::NO_STORE_HEADERS + Router::CORS_HEADERS as $name => $value) {
            $headers->set($name, $value);
        }
    }
}
