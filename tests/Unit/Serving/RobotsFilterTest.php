<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Serving\RobotsFilter;
use Globetrotters\AiPresenceBundle\Settings\Options;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RobotsFilterTest extends TestCase
{
    private const BASE_URL = 'https://nantes.globetrotters.ai';

    /** The origin Request::create() gives a path-only URI — this site, not Globetrotters. */
    private const REQUEST_ORIGIN = 'http://localhost';

    private function filter(bool $connected = true, bool $cached = true): RobotsFilter
    {
        $pool = new ArrayAdapter();
        $cache = new ArtefactCache($pool);
        if ($cached) {
            $cache->store(['llms.txt' => 'x'], 'v1', 0);
        }

        return new RobotsFilter(new Options($pool, $connected ? self::BASE_URL : '', 'daily', '/'), $cache);
    }

    private function responseEvent(RobotsFilter $filter, string $uri, Response $response): Response
    {
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($uri),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
        $filter->onKernelResponse($event);

        return $event->getResponse();
    }

    private function exceptionEvent(RobotsFilter $filter, \Throwable $throwable, string $uri = '/robots.txt', string $method = 'GET'): ExceptionEvent
    {
        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($uri, $method),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
        $filter->onKernelException($event);

        return $event;
    }

    /**
     * The canonical copy is generated from the backend registry, not typed:
     *
     *     cd gt-backend/libs/globetrotters-business && uv run python -c \
     *       "from globetrotters.business.presence.services.ai_user_agents \
     *        import render_robots_groups; print(render_robots_groups(), end='')"
     *
     * Regenerate the fixture that way when the registry gains a name; this
     * assertion is what turns a drifting hand-typed copy into a red build.
     */
    public function testAiUserAgentGroupsMatchTheCanonicalFixture(): void
    {
        $fixture = file_get_contents(__DIR__.'/../../Fixtures/robots-ai-user-agent-groups.txt');

        self::assertIsString($fixture);
        self::assertSame($fixture, RobotsFilter::aiUserAgentGroups());
    }

    public function testEveryNamedGroupCarriesItsOwnContentSignal(): void
    {
        $block = RobotsFilter::buildBlock(self::REQUEST_ORIGIN);

        // A crawler obeys only its own most-specific group (RFC 9309 §2.2.1),
        // so one signal line per group is the only placement that reaches
        // every named agent.
        self::assertSame(
            substr_count($block, 'User-agent: '),
            substr_count($block, 'Content-Signal: search=yes, ai-input=yes, ai-train=yes'),
        );
    }

    public function testBuildBlockIsTheMarkerTheGroupsAndTheSitemap(): void
    {
        $expected = RobotsFilter::MARKER."\n"
            .RobotsFilter::aiUserAgentGroups()
            .'Sitemap: '.self::REQUEST_ORIGIN."/sitemap.xml\n";

        self::assertSame($expected, RobotsFilter::buildBlock(self::REQUEST_ORIGIN));
    }

    public function testBuildBlockTrimsATrailingSlashFromTheOrigin(): void
    {
        self::assertStringContainsString(
            'Sitemap: https://example.test/sitemap.xml',
            RobotsFilter::buildBlock('https://example.test/'),
        );
    }

    /**
     * The decorate path appends to a robots.txt the app may already own,
     * wildcard group included; a second one is a duplicate that can override
     * the site's real crawl rules. Both lanes emit the same block, so the
     * guard belongs on the block itself.
     */
    public function testEmitsNoWildcardGroup(): void
    {
        self::assertStringNotContainsString('User-agent: *', RobotsFilter::buildBlock(self::REQUEST_ORIGIN));
    }

    /**
     * The backend emits `Agentmap: /.well-known/ai-catalog.json` in both its
     * lanes. That path is root-relative and this bundle does not serve
     * ai-catalog.json, so the line would advertise a 404 at the customer's
     * own apex. Adding it requires serving the artefact first.
     */
    public function testEmitsNoAgentmapLine(): void
    {
        self::assertStringNotContainsString('Agentmap:', RobotsFilter::buildBlock(self::REQUEST_ORIGIN));
    }

    public function testBuildBlockWithoutBaseUrlOmitsSitemap(): void
    {
        self::assertStringNotContainsString('Sitemap:', RobotsFilter::buildBlock(''));
    }

    public function testDecoratesAppServedRobots(): void
    {
        $response = new Response("User-agent: *\nDisallow: /admin\n", 200, ['Content-Type' => 'text/plain']);
        $decorated = $this->responseEvent($this->filter(), '/robots.txt', $response);

        $content = (string) $decorated->getContent();
        self::assertStringStartsWith("User-agent: *\nDisallow: /admin\n\n# Globetrotters AI Presence\n", $content);
        self::assertStringContainsString("User-agent: GPTBot\nAllow: /\nContent-Signal: search=yes, ai-input=yes, ai-train=yes\n", $content);
        self::assertStringContainsString('Sitemap: '.self::REQUEST_ORIGIN.'/sitemap.xml', $content);
        // The app's own wildcard group is the only one in the file.
        self::assertSame(1, substr_count($content, 'User-agent: *'));
    }

    public function testSitemapLineNamesTheRequestHostNotTheGlobetrottersOrigin(): void
    {
        $response = new Response("User-agent: *\n", 200, ['Content-Type' => 'text/plain']);
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('https://apex.example/robots.txt'),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
        $this->filter()->onKernelResponse($event);

        $content = (string) $response->getContent();
        self::assertStringContainsString('Sitemap: https://apex.example/sitemap.xml', $content);
        self::assertStringNotContainsString(self::BASE_URL, $content);
    }

    public function testGeneratedRobotsAlsoNamesTheRequestHost(): void
    {
        $event = $this->exceptionEvent($this->filter(), new NotFoundHttpException(), 'https://apex.example/robots.txt');

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertStringContainsString('Sitemap: https://apex.example/sitemap.xml', (string) $response->getContent());
    }

    public function testDecorationRemovesStaleBodyMetadata(): void
    {
        $response = new Response("User-agent: *\n", 200, [
            'Content-Type' => 'text/plain',
            'Content-Length' => '14',
            'ETag' => '"robots-v1"',
            'Last-Modified' => 'Wed, 21 Oct 2015 07:28:00 GMT',
            'Content-MD5' => 'old-md5',
            'Digest' => 'sha-256=old',
            'Content-Digest' => 'sha-256=:old:',
            'Repr-Digest' => 'sha-256=:old:',
        ]);
        $this->responseEvent($this->filter(), '/robots.txt', $response);

        self::assertSame('"'.hash('sha256', (string) $response->getContent()).'"', $response->getEtag());
        foreach (['Content-Length', 'Last-Modified', 'Content-MD5', 'Digest', 'Content-Digest', 'Repr-Digest'] as $header) {
            self::assertFalse($response->headers->has($header), $header.' must not describe the undecorated body');
        }
    }

    public function testDoesNotDecorateTwice(): void
    {
        $response = new Response("User-agent: *\n", 200, ['Content-Type' => 'text/plain']);
        $filter = $this->filter();
        $this->responseEvent($filter, '/robots.txt', $response);
        $this->responseEvent($filter, '/robots.txt', $response);

        self::assertSame(1, substr_count((string) $response->getContent(), RobotsFilter::MARKER));
    }

    public function testSkipsWhenNotConnected(): void
    {
        $response = new Response("User-agent: *\n", 200, ['Content-Type' => 'text/plain']);
        $this->responseEvent($this->filter(connected: false), '/robots.txt', $response);

        self::assertStringNotContainsString(RobotsFilter::MARKER, (string) $response->getContent());
    }

    public function testSkipsWhenCacheEmpty(): void
    {
        $response = new Response("User-agent: *\n", 200, ['Content-Type' => 'text/plain']);
        $this->responseEvent($this->filter(cached: false), '/robots.txt', $response);

        self::assertStringNotContainsString(RobotsFilter::MARKER, (string) $response->getContent());
    }

    public function testSkipsOtherPaths(): void
    {
        $response = new Response('hello', 200, ['Content-Type' => 'text/plain']);
        $this->responseEvent($this->filter(), '/hello.txt', $response);

        self::assertSame('hello', $response->getContent());
    }

    public function testSkipsNonPlainTextResponses(): void
    {
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html']);
        $this->responseEvent($this->filter(), '/robots.txt', $response);

        self::assertSame('<html></html>', $response->getContent());
    }

    public function testGeneratesRobotsWhenAppReturnsExplicit404Response(): void
    {
        // A catch-all controller returns a 404 Response instead of throwing, so
        // onKernelException never fires — onKernelResponse must still generate.
        $response = new Response('Not Found', 404, ['Content-Type' => 'text/html']);
        $generated = $this->responseEvent($this->filter(), '/robots.txt', $response);

        self::assertSame(200, $generated->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $generated->headers->get('Content-Type'));
        self::assertStringStartsWith(RobotsFilter::MARKER, (string) $generated->getContent());
    }

    public function testDoesNotGenerateOn404WhenNotAdvertising(): void
    {
        $response = new Response('Not Found', 404);
        $result = $this->responseEvent($this->filter(cached: false), '/robots.txt', $response);

        self::assertSame(404, $result->getStatusCode());
        self::assertSame('Not Found', $result->getContent());
    }

    public function testDoesNotDecorateOnPost(): void
    {
        $response = new Response("User-agent: *\n", 200, ['Content-Type' => 'text/plain']);
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/robots.txt', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
        $this->filter()->onKernelResponse($event);

        self::assertStringNotContainsString(RobotsFilter::MARKER, (string) $response->getContent());
    }

    public function testServesGeneratedRobotsOn404(): void
    {
        $event = $this->exceptionEvent($this->filter(), new NotFoundHttpException());

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringStartsWith(RobotsFilter::MARKER, (string) $response->getContent());
    }

    public function testDoesNotServeOnOtherExceptions(): void
    {
        $event = $this->exceptionEvent($this->filter(), new AccessDeniedHttpException());

        self::assertNull($event->getResponse());
    }

    public function testDoesNotServeOnOtherPaths(): void
    {
        $event = $this->exceptionEvent($this->filter(), new NotFoundHttpException(), '/missing');

        self::assertNull($event->getResponse());
    }

    public function testDoesNotServeOnPost(): void
    {
        $event = $this->exceptionEvent($this->filter(), new NotFoundHttpException(), '/robots.txt', 'POST');

        self::assertNull($event->getResponse());
    }

    public function testDoesNotServeWhenCacheEmpty(): void
    {
        $event = $this->exceptionEvent($this->filter(cached: false), new NotFoundHttpException());

        self::assertNull($event->getResponse());
    }
}
