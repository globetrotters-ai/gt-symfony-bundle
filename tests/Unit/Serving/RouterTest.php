<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Serving\ContentTypes;
use Globetrotters\AiPresenceBundle\Serving\Router;
use Globetrotters\AiPresenceBundle\Serving\Sitemap;
use Globetrotters\AiPresenceBundle\Settings\Options;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class RouterTest extends TestCase
{
    /**
     * A key shaped like the ones the presence stack issues (32 hex chars).
     */
    private const INDEXNOW_KEY = 'e715a2e7bf3c4a1d8e0b6f9c2d5a7e14';

    private ArtefactCache $cache;
    private Options $options;
    private Router $router;

    protected function setUp(): void
    {
        $pool = new ArrayAdapter();
        $this->cache = new ArtefactCache($pool);
        $this->cache->store([
            'llms.txt' => 'llms body',
            '.well-known/mcp.json' => '{"m":1}',
        ], 'v1', 0);
        $this->options = new Options($pool, 'https://nantes.globetrotters.ai', 'daily', '/');
        $this->router = new Router($this->cache, $this->options, new Sitemap($this->options, $this->cache));
    }

    private function storeKey(string $key): void
    {
        $this->options->updateState(['indexnow_key' => $key]);
    }

    private function event(string $uri, string $method = 'GET', int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($uri, $method),
            $type,
        );
    }

    public function testSubscribesAfterValidationButBeforeRouterAndFirewall(): void
    {
        $events = Router::getSubscribedEvents();

        self::assertSame(['onKernelRequest', 64], $events[KernelEvents::REQUEST]);
        self::assertLessThan(256, Router::PRIORITY, 'Symfony request validation must run first');
        self::assertGreaterThan(32, Router::PRIORITY, 'The bundle must still pre-empt routing');
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
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
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
        $cold = new ArtefactCache(new ArrayAdapter());
        $router = new Router($cold, $this->options, new Sitemap($this->options, $cold));
        $event = $this->event('/llms.txt');
        $router->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testServesTheGeneratedSitemapAtItsOwnPath(): void
    {
        $event = $this->event('https://apex.example/'.Sitemap::PATH);
        $this->router->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(Sitemap::CONTENT_TYPE, $response->headers->get('Content-Type'));
        self::assertStringContainsString('<loc>https://apex.example/llms.txt</loc>', (string) $response->getContent());
    }

    /**
     * A crawler reading a sitemap is not an agent fetching the presence, so it
     * must not reach Presence Analytics — but the no-store headers still apply,
     * and the CORS grant still does not.
     */
    public function testTheSitemapIsTaggedForHeadersButNotForCapture(): void
    {
        $event = $this->event('https://apex.example/'.Sitemap::PATH);
        $this->router->onKernelRequest($event);

        $attributes = $event->getRequest()->attributes;
        self::assertTrue($attributes->get(Router::ATTRIBUTE_SITEMAP));
        self::assertFalse($attributes->has(Router::ATTRIBUTE_PATH));
        self::assertSame(Router::sitemapHeaders(), [
            'Content-Type' => Sitemap::CONTENT_TYPE,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
            'Surrogate-Control' => 'no-store',
        ]);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', Router::sitemapHeaders());
    }

    /**
     * `ai-sitemap.xml` is itself a well-formed key filename (a ten-character
     * [A-Za-z0-9-] stem), so an install whose key happened to be `ai-sitemap`
     * must not shadow the sitemap. Structural, like the artefact map being
     * matched before both.
     */
    public function testTheSitemapIsMatchedBeforeAKeyThatWouldShadowIt(): void
    {
        $this->storeKey('ai-sitemap');

        $event = $this->event('https://apex.example/'.Sitemap::PATH);
        $this->router->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertStringContainsString('<urlset', (string) $response->getContent());
    }

    /**
     * The site's own /sitemap.xml is the application's, whatever it does with
     * it — the bundle never claims that path.
     */
    public function testNeverClaimsThePlainSitemapPath(): void
    {
        $event = $this->event('/sitemap.xml');
        $this->router->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testTheSitemapIsNotServedOnAColdCache(): void
    {
        $cold = new ArtefactCache(new ArrayAdapter());
        $router = new Router($cold, $this->options, new Sitemap($this->options, $cold));
        $event = $this->event('/'.Sitemap::PATH);
        $router->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testServesTheIndexNowKeyAtItsOwnPath(): void
    {
        $this->storeKey(self::INDEXNOW_KEY);

        $event = $this->event('/'.self::INDEXNOW_KEY.'.txt');
        $this->router->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        // Byte-equal to the key and nothing else — no trailing newline. IndexNow
        // compares the file's contents to the key it was handed, and the edge
        // proxy's serve_indexnow_key returns ``content=key`` verbatim.
        self::assertSame(self::INDEXNOW_KEY, $response->getContent());
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('no-store', $response->headers->get('Surrogate-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertTrue($event->isPropagationStopped());
    }

    public function testTheBundleGrantsNoCorsOnAServedIndexNowKey(): void
    {
        // Deliberate: CORS is granted to the artefacts because a browser-context
        // agent client cannot read a discovery document without it. The key file
        // is fetched server-side by a search engine and needs no such grant, and
        // this is the apex of a site we do not own.
        //
        // Scoped claim on purpose — the bundle does not *add* the header. An app
        // that stamps CORS on every response keeps doing so here and is not
        // undone, which costs nothing: the key is public by construction.
        $this->storeKey(self::INDEXNOW_KEY);

        $event = $this->event('/'.self::INDEXNOW_KEY.'.txt');
        $this->router->onKernelRequest($event);

        self::assertNotNull($event->getResponse());
        self::assertFalse($event->getResponse()->headers->has('Access-Control-Allow-Origin'));
    }

    public function testTheKeyPathFallsThroughWhenNoKeyIsStored(): void
    {
        // A keyless environment (dev, and staging permanently). Falling through
        // is what makes it the application's normal 404 rather than a 200 with a
        // body that would fail verification for whoever fetched it.
        $event = $this->event('/'.self::INDEXNOW_KEY.'.txt');
        $this->router->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testAKeyFileForSomeOtherKeyFallsThrough(): void
    {
        $this->storeKey(self::INDEXNOW_KEY);

        $event = $this->event('/4498b441c07d4e2fa9b31c8e6d02f5a7.txt');
        $this->router->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('servedPaths')]
    public function testNoServedArtefactCanBeShadowedByAStoredKey(string $path): void
    {
        // Driven off ContentTypes::paths(), not a hand-listed sample, because the
        // guarantee is the matching *order* in onKernelRequest() rather than a
        // coincidence of the key grammar — `llms-full.txt` would parse as a
        // well-formed key file, and it is one decision away from being served
        // here. A path added to the map is covered by this test on its own.
        $pool = new ArrayAdapter();
        $cache = new ArtefactCache($pool);
        $cache->store([$path => 'artefact body'], 'v1', 0);
        $options = new Options($pool, 'https://nantes.globetrotters.ai', 'daily', '/');
        $options->updateState(['indexnow_key' => self::INDEXNOW_KEY]);

        $event = $this->event('/'.$path);
        (new Router($cache, $options, new Sitemap($options, $cache)))->onKernelRequest($event);

        self::assertNotNull($event->getResponse());
        self::assertSame('artefact body', $event->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function servedPaths(): iterable
    {
        foreach (ContentTypes::paths() as $path) {
            yield $path => [$path];
        }
    }

    public function testServingTheKeyIsNotMarkedAsAgentTraffic(): void
    {
        // Presence Analytics counts agent fetches of the artefact set. A search
        // engine reading the key to verify host control is neither an agent nor
        // an artefact fetch, and folding it in would inflate the reported counts.
        $this->storeKey(self::INDEXNOW_KEY);

        $event = $this->event('/'.self::INDEXNOW_KEY.'.txt');
        $this->router->onKernelRequest($event);

        $attributes = $event->getRequest()->attributes;
        self::assertNotNull($event->getResponse());
        self::assertFalse($attributes->has(Router::ATTRIBUTE_PATH));
        // But it is still marked, so the no-store headers are re-asserted below
        // every listener an integrating application might register.
        self::assertTrue($attributes->get(Router::ATTRIBUTE_KEY));
    }

    public function testTheKeyIsServedAtOneUrlOnly(): void
    {
        $this->storeKey(self::INDEXNOW_KEY);

        $request = Request::create('/x');
        $request->server->set('REQUEST_URI', '//'.self::INDEXNOW_KEY.'.txt');
        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
        $this->router->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testTheKeyAnswersReadMethodsOnly(): void
    {
        $this->storeKey(self::INDEXNOW_KEY);

        $event = $this->event('/'.self::INDEXNOW_KEY.'.txt', 'POST');
        $this->router->onKernelRequest($event);

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
