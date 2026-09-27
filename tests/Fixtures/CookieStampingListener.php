<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Fixtures;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * An application that sets a cookie on every response it sees — a session
 * listener, a consent banner, a CSRF bundle. The page-view counter promises it
 * never sets a cookie, and that has to hold against the host app too.
 */
final class CookieStampingListener implements EventSubscriberInterface
{
    public const COOKIE = 'app_session';

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', 0]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $event->getResponse()->headers->setCookie(Cookie::create(self::COOKIE, 'stamped'));
    }
}
