<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Analytics\EventRecorder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records one server-log event per served artefact request.
 *
 * On kernel.terminate rather than on the serve path itself: terminate runs
 * *after* the response has been sent to the client, so buffering an event costs
 * the agent nothing at all — not even the microseconds an append would take.
 * It is also where the final status is known, after every other listener has
 * had its say about the response.
 *
 * {@see Router} marks the request; an unmarked request is not ours and is
 * ignored, so a cold cache or a path the app served itself never produces an
 * event.
 */
final class ArtefactCaptureSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly EventRecorder $recorder)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => ['onKernelTerminate', 0]];
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();

        $path = $request->attributes->get(Router::ATTRIBUTE_PATH);
        if (!\is_string($path) || '' === $path) {
            return;
        }

        $bytes = $request->attributes->get(Router::ATTRIBUTE_BYTES);

        $this->recorder->record(
            $request,
            $path,
            $event->getResponse()->getStatusCode(),
            \is_int($bytes) ? $bytes : 0,
        );
    }
}
