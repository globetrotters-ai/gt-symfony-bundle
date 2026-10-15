<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Analytics\CaptureContext;
use Globetrotters\AiPresenceBundle\Analytics\SiteCapture;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records site pages fetched by bots, on kernel.terminate: after the response
 * is sent, with its final status.
 *
 * Only requests this bundle did not answer itself: an artefact, the sitemap,
 * the IndexNow key and the page-view beacon are marked by {@see Router} and
 * {@see PageViewEndpoint} and keep their own lanes (or none). This subscriber
 * never triggers a flush: {@see OpportunisticFlushSubscriber} still fires only
 * on our own requests, so the application's traffic never pays for one.
 *
 * Also notes CDN markers and the page cache for the capture context, on the
 * requests it captures and on served artefacts.
 */
final class SiteCaptureSubscriber implements EventSubscriberInterface
{
    private const OWN_ATTRIBUTES = [
        Router::ATTRIBUTE_KEY,
        Router::ATTRIBUTE_SITEMAP,
        Router::ATTRIBUTE_PAGE_VIEW,
    ];

    public function __construct(
        private readonly SiteCapture $capture,
        private readonly CaptureContext $context,
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
            if (!$event->isMainRequest()) {
                return;
            }
            // A served artefact is agent traffic arriving through whatever
            // sits in front of the app, so it counts for the context too.
            if ($request->attributes->has(Router::ATTRIBUTE_PATH)) {
                $this->context->observe($request, $event->getResponse());

                return;
            }
            foreach (self::OWN_ATTRIBUTES as $attribute) {
                if ($request->attributes->has($attribute)) {
                    return;
                }
            }
            if ($this->capture->capture($request, $event->getResponse())) {
                $this->context->observe($request, $event->getResponse());
            }
        } catch (\Throwable) {
            // The response is already on the wire.
        }
    }
}
