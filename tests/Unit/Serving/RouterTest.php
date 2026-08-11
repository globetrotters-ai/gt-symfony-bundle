<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Serving\Router;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class RouterTest extends TestCase
{
    private ArtefactCache $cache;
    private Router $router;

    protected function setUp(): void
    {
        $this->cache = new ArtefactCache(new ArrayAdapter());
        $this->cache->store([
            'llms.txt' => 'llms body',
            '.well-known/mcp.json' => '{"m":1}',
        ], 'v1', 0);
        $this->router = new Router($this->cache);
    }

    private function event(string $uri, string $method = 'GET', int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($uri, $method),
            $type,
        );
    }

    public function testSubscribesBeforeRouterAndFirewall(): void
    {
        $events = Router::getSubscribedEvents();

        self::assertSame(['onKernelRequest', 512], $events[KernelEvents::REQUEST]);
    }

    public function testServesCachedArtefactWithExactHeaders(): void
    {
        $event = $this->event('/llms.txt');
        $this->router->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('llms body', $response->getContent());
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        // Uncacheable is a precondition for measurement, not an optimisation:
        // a shared TTL in front of the origin means the app never runs and the
        // reported hit count is silently low.
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('no-store', $response->headers->get('Surrogate-Control'));
        self::assertTrue($event->isPropagationStopped());
    }

    public function testMarksTheRequestForCapture(): void
    {
        $event = $this->event('/llms.txt');
        $this->router->onKernelRequest($event);

        $attributes = $event->getRequest()->attributes;
        self::assertSame('/llms.txt', $attributes->get(Router::ATTRIBUTE_PATH));
        self::assertSame(\strlen('llms body'), $attributes->get(Router::ATTRIBUTE_BYTES));
    }

    public function testReportsTheCanonicalPathRatherThanTheRequestUri(): void
    {
        // The backend matches the six paths exactly and drops /llms.txt?v=2 at
        // ingest, so the query string must not reach the reported path.
        $event = $this->event('/llms.txt?v=2');
        $this->router->onKernelRequest($event);

        self::assertSame('/llms.txt', $event->getRequest()->attributes->get(Router::ATTRIBUTE_PATH));
    }

    public function testDoesNotMarkARequestItDidNotServe(): void
    {
        $event = $this->event('/llms-full.txt');
        $this->router->onKernelRequest($event);

        self::assertFalse($event->getRequest()->attributes->has(Router::ATTRIBUTE_PATH));
    }

    public function testServesWellKnownPath(): void
    {
        $event = $this->event('/.well-known/mcp.json');
        $this->router->onKernelRequest($event);

        self::assertNotNull($event->getResponse());
        self::assertSame('application/json; charset=utf-8', $event->getResponse()->headers->get('Content-Type'));
    }

    public function testQueryStringIsIgnored(): void
    {
        $event = $this->event('/llms.txt?utm_source=x');
        $this->router->onKernelRequest($event);

        self::assertNotNull($event->getResponse());
    }

    public function testNonCanonicalDoubleSlashPathFallsThrough(): void
    {
        // Request::create normalises "//llms.txt" away, so drive getPathInfo()
        // through the raw REQUEST_URI the way a real double-slash request would.
        $request = Request::create('/x');
        $request->server->set('REQUEST_URI', '//llms.txt');
        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
        $this->router->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testUnknownPathFallsThrough(): void
    {
        $event = $this->event('/about');
        $this->router->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testCacheMissFallsThrough(): void
    {
        // schema.json is a known path but not cached.
        $event = $this->event('/schema.json');
        $this->router->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testColdCacheFallsThrough(): void
    {
        $router = new Router(new ArtefactCache(new ArrayAdapter()));
        $event = $this->event('/llms.txt');
        $router->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testPostIsNotIntercepted(): void
    {
        $event = $this->event('/llms.txt', 'POST');
        $this->router->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testSubRequestIsNotIntercepted(): void
    {
        $event = $this->event('/llms.txt', 'GET', HttpKernelInterface::SUB_REQUEST);
        $this->router->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }
}
