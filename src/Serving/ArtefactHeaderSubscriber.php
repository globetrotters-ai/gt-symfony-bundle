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
 *
 * The served IndexNow key gets the same no-store treatment for a reason of its
 * own — it is mutable state that reaches an install only on its next refresh, so
 * a cached copy of a rotated-away key fails verification for as long as it lives
 * — but **not** the CORS grant. That grant exists so a browser-context agent
 * client can read a discovery document; the key file is fetched server-side by a
 * search engine, and this is the apex of a site we do not own.
 *
 * Not granting is all this does: a CORS header an application stamps on every
 * response is left in place rather than stripped. Deliberate. The key is public
 * by construction — its whole job is to be readable off the host it verifies —
 * so cross-origin readability discloses nothing, and reaching in to undo an
 * integrator's own CORS policy on their own domain would cost them something
 * for no gain. The no-store override earns its intrusion because a shared TTL
 * breaks verification and measurement; there is no equivalent failure here.
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
        $attributes = $event->getRequest()->attributes;
        if ($attributes->has(Router::ATTRIBUTE_PATH)) {
            $restore = Router::NO_STORE_HEADERS + Router::CORS_HEADERS;
        } elseif (true === $attributes->get(Router::ATTRIBUTE_KEY)) {
            $restore = Router::NO_STORE_HEADERS;
        } else {
            return;
        }

        $headers = $event->getResponse()->headers;
        foreach ($restore as $name => $value) {
            $headers->set($name, $value);
        }
    }
}
