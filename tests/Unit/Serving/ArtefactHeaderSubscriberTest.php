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
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
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

    public function testRestoresNoStoreOnAServedIndexNowKey(): void
    {
        // The key is mutable state that reaches an install only on its next
        // refresh, so a shared TTL over it fails verification for as long as the
        // cached copy lives — on top of the measurement reason above.
        $response = new Response('e715a2e7bf3c4a1d8e0b6f9c2d5a7e14');
        $response->setPublic();
        $response->setSharedMaxAge(600);

        $this->subscribeKey($response);

        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('no-store', $response->headers->get('Surrogate-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testDoesNotGrantCorsOnAServedIndexNowKey(): void
    {
        // CORS is granted to the artefacts because a browser-context agent
        // client cannot read a discovery document without it. The key file is
        // fetched server-side by a search engine, and this is the apex of a site
        // we do not own, so the grant stays scoped to the paths that need it.
        $response = new Response('e715a2e7bf3c4a1d8e0b6f9c2d5a7e14');

        $this->subscribeKey($response);

        self::assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    public function testLeavesAnApplicationsOwnCorsHeaderOnAServedKeyAlone(): void
    {
        // The counterpart to the test above, pinning the limit of that claim:
        // not granting is not the same as stripping. An app with a global CORS
        // policy (NelmioCorsBundle over `^/`) keeps it here, deliberately — the
        // key is public by construction, so cross-origin readability discloses
        // nothing, and undoing an integrator's policy on their own domain would
        // cost them something for no gain. Contrast Cache-Control just below,
        // where a shared TTL breaks verification and the override is earned.
        $response = new Response('e715a2e7bf3c4a1d8e0b6f9c2d5a7e14');
        $response->headers->set('Access-Control-Allow-Origin', 'https://app.example');
        $response->setSharedMaxAge(600);

        $this->subscribeKey($response);

        self::assertSame('https://app.example', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    private function subscribeKey(Response $response): void
    {
        $request = Request::create('/e715a2e7bf3c4a1d8e0b6f9c2d5a7e14.txt');
        $request->attributes->set(Router::ATTRIBUTE_KEY, true);

        (new ArtefactHeaderSubscriber())->onKernelResponse(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));
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
