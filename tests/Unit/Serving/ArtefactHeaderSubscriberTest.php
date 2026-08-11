<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Serving\ArtefactHeaderSubscriber;
use Globetrotters\AiPresenceBundle\Serving\Router;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ArtefactHeaderSubscriberTest extends TestCase
{
    public function testRunsAfterEverythingElseOnKernelResponse(): void
    {
        $events = ArtefactHeaderSubscriber::getSubscribedEvents();

        self::assertSame(['onKernelResponse', -1024], $events[KernelEvents::RESPONSE]);
    }

    public function testRestoresNoStoreOverAnApplicationsOwnCachingDirectives(): void
    {
        // The failure this exists to prevent: an app-level listener, a #[Cache]
        // attribute or setSharedMaxAge() stamping a shared TTL over the
        // artefact response, after which the origin stops executing and the hit
        // count is silently low.
        $response = new Response('body');
        $response->setPublic();
        $response->setSharedMaxAge(600);

        $this->subscribe($response, marked: true);

        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('no-store', $response->headers->get('Surrogate-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testLeavesTheRestOfTheApplicationAlone(): void
    {
        $response = new Response('page');
        $response->setPublic();
        $response->setMaxAge(600);

        $this->subscribe($response, marked: false);

        self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        self::assertFalse($response->headers->has('Surrogate-Control'));
    }

    public function testIgnoresSubRequests(): void
    {
        $response = new Response('fragment');
        $response->setPublic();

        $this->subscribe($response, marked: true, main: false);

        self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    private function subscribe(Response $response, bool $marked, bool $main = true): void
    {
        $request = Request::create('/llms.txt');
        if ($marked) {
            $request->attributes->set(Router::ATTRIBUTE_PATH, '/llms.txt');
        }

        (new ArtefactHeaderSubscriber())->onKernelResponse(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            $main ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
            $response,
        ));
    }
}
