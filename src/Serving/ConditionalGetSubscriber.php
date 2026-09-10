<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Revalidates a rewritten response against the entity-tag that will actually be
 * sent — once, after every rewriter has had its turn.
 *
 * {@see BodyMetadata} recomputes the tag on each rewrite, but the revalidation
 * cannot happen there. Up to three subscribers rewrite the same response
 * ({@see HeadInjector} and {@see BreadcrumbInjector} at -10,
 * {@see RobotsFilter} at -20), and each intermediate body has a tag of its own
 * that is never served. Revalidating against one of those would hand a client a
 * 304 for a representation this response is no longer going to be — a client
 * that once cached the JSON-LD-only homepage would keep being told "not
 * modified" after the breadcrumb was switched on, and would never receive it.
 *
 * So the rewriters only mark the request, and the decision is made here, below
 * every one of them, against the final bytes. Priority -64 is chosen to sit
 * after RobotsFilter and well above {@see ArtefactHeaderSubscriber} at -1024,
 * which re-asserts headers on Router-served artefacts and never rewrites a body.
 *
 * A HEAD response is revalidated the same way: RobotsFilter gives it the
 * entity-tag of the decorated GET it stands for, so a client holding that tag
 * gets the same 304 from either method.
 */
final class ConditionalGetSubscriber implements EventSubscriberInterface
{
    public const PRIORITY = -64;

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', self::PRIORITY]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (true !== $request->attributes->get(BodyMetadata::ATTRIBUTE_REWRITTEN)) {
            return;
        }

        // Gates on a cacheable method and on the response carrying a tag at all,
        // and turns a match into a 304 by stripping the body.
        $event->getResponse()->isNotModified($request);
    }
}
