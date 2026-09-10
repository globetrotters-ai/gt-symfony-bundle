<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Fixtures;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Plays Symfony's CacheAttributeListener: at its priority (-10), after
 * ResponseListener has prepared the response, it adds the application's
 * Last-Modified to a robots.txt that lacks one — which is how an app using
 * ``#[Cache(lastModified: …)]`` gets the header. A robots decoration running
 * before it would have the stale validator stamped back on afterwards.
 */
final class LateValidatorListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -10]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if ('/robots.txt' !== $event->getRequest()->getPathInfo()) {
            return;
        }

        $response = $event->getResponse();
        if (!$response->headers->has('Last-Modified')) {
            $response->headers->set('Last-Modified', AntagonistController::ROBOTS_LAST_MODIFIED);
        }
    }
}
