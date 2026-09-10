<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Serving\Sitemap;
use Globetrotters\AiPresenceBundle\Serving\SitemapFilter;
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

final class SitemapFilterTest extends TestCase
{
    private const BASE_URL = 'https://nantes.globetrotters.ai';

    private const URI = 'https://apex.example/sitemap.xml';

    private function filter(bool $connected = true, bool $cached = true): SitemapFilter
    {
        $pool = new ArrayAdapter();
        $cache = new ArtefactCache($pool);
        if ($cached) {
            $cache->store(['llms.txt' => 'x'], 'v1', 0);
        }
        $options = new Options($pool, $connected ? self::BASE_URL : '', 'daily', '/');

        return new SitemapFilter($options, $cache, new Sitemap($options, $cache));
    }

    private function responseEvent(SitemapFilter $filter, Response $response, string $uri = self::URI, string $method = 'GET'): Response
    {
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($uri, $method),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
        $filter->onKernelResponse($event);

        return $event->getResponse();
    }

    private function exceptionEvent(SitemapFilter $filter, \Throwable $throwable, string $uri = self::URI, string $method = 'GET'): ExceptionEvent
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

    public function testServesAGeneratedSitemapWhenTheAppThrows404(): void
    {
        $event = $this->exceptionEvent($this->filter(), new NotFoundHttpException());

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(Sitemap::CONTENT_TYPE, $response->headers->get('Content-Type'));
        self::assertStringContainsString('<loc>https://apex.example/llms.txt</loc>', (string) $response->getContent());
    }

    public function testServesAGeneratedSitemapWhenTheAppReturnsAnExplicit404Response(): void
    {
        $generated = $this->responseEvent($this->filter(), new Response('Not Found', 404, ['Content-Type' => 'text/html']));

        self::assertSame(200, $generated->getStatusCode());
        self::assertSame(Sitemap::CONTENT_TYPE, $generated->headers->get('Content-Type'));
        self::assertStringContainsString('<urlset', (string) $generated->getContent());
    }

    /**
     * The point of the class: the readiness probe reads a hardcoded
     * /sitemap.xml, so the discovery URLs have to land in the application's
     * own document or they land nowhere.
     */
    public function testDecoratesAnApplicationServedSitemap(): void
    {
        $app = '<?xml version="1.0" encoding="UTF-8"?><urlset><url><loc>https://apex.example/about</loc></url></urlset>';
        $response = $this->responseEvent($this->filter(), new Response($app, 200, ['Content-Type' => Sitemap::CONTENT_TYPE]));

        $content = (string) $response->getContent();
        self::assertSame(200, $response->getStatusCode());
        // Additive: the app's own entry survives, ours are added before the close.
        self::assertStringContainsString('<loc>https://apex.example/about</loc>', $content);
        self::assertStringContainsString('<loc>https://apex.example/llms.txt</loc>', $content);
        self::assertStringEndsWith('</urlset>', $content);
    }

    public function testDecorationIsIdempotent(): void
    {
        $app = '<?xml version="1.0" encoding="UTF-8"?><urlset><url><loc>https://apex.example/about</loc></url></urlset>';
        $response = new Response($app, 200, ['Content-Type' => Sitemap::CONTENT_TYPE]);
        $filter = $this->filter();
        $this->responseEvent($filter, $response);
        $this->responseEvent($filter, $response);

        self::assertSame(1, substr_count((string) $response->getContent(), Sitemap::MARKER));
    }

    /**
     * A sitemap index's children are other documents this response does not
     * contain, so there is nothing here to merge into.
     */
    public function testLeavesASitemapIndexAlone(): void
    {
        $app = '<?xml version="1.0" encoding="UTF-8"?><sitemapindex><sitemap><loc>https://apex.example/s1.xml</loc></sitemap></sitemapindex>';
        $response = $this->responseEvent($this->filter(), new Response($app, 200, ['Content-Type' => Sitemap::CONTENT_TYPE]));

        self::assertSame($app, $response->getContent());
    }

    public function testLeavesACompressedSitemapAlone(): void
    {
        $app = '<?xml version="1.0" encoding="UTF-8"?><urlset><url><loc>https://apex.example/about</loc></url></urlset>';
        $response = $this->responseEvent($this->filter(), new Response($app, 200, [
            'Content-Type' => Sitemap::CONTENT_TYPE,
            'Content-Encoding' => 'gzip',
        ]));

        self::assertSame($app, $response->getContent());
    }

    public function testLeavesANonXmlResponseAlone(): void
    {
        $response = $this->responseEvent($this->filter(), new Response('<html></html>', 200, ['Content-Type' => 'text/html']));

        self::assertSame('<html></html>', $response->getContent());
    }

    public function testDoesNotDecorateOnHead(): void
    {
        $response = new Response('', 200, ['Content-Type' => Sitemap::CONTENT_TYPE]);
        $this->responseEvent($this->filter(), $response, method: 'HEAD');

        self::assertSame('', $response->getContent());
    }

    /**
     * The body embeds the origin the request arrived on, so it must never be
     * reused for a host alias sharing a cache entry.
     */
    public function testTheGeneratedResponseIsNotCacheable(): void
    {
        $response = $this->exceptionEvent($this->filter(), new NotFoundHttpException())->getResponse();

        self::assertNotNull($response);
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('no-store', $response->headers->get('Surrogate-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * Response::prepare() has already emptied a HEAD body by the time this
     * subscriber runs at -20, so the generated document must not be written
     * back into it. The exception lane is prepared after it sets the response
     * and needs no such guard.
     */
    public function testGeneratesAnEmptyBodiedSitemapOnHeadInTheResponseLane(): void
    {
        $generated = $this->responseEvent($this->filter(), new Response('', 404), method: 'HEAD');

        self::assertSame(200, $generated->getStatusCode());
        self::assertSame(Sitemap::CONTENT_TYPE, $generated->headers->get('Content-Type'));
        self::assertSame('', $generated->getContent());
    }

    public function testStaleBodyMetadataIsDropped(): void
    {
        $response = new Response('Not Found', 404, ['Content-Type' => 'text/html', 'Content-Length' => '9']);
        $this->responseEvent($this->filter(), $response);

        self::assertFalse($response->headers->has('Content-Length'));
    }

    public function testDoesNotServeOnOtherPaths(): void
    {
        $event = $this->exceptionEvent($this->filter(), new NotFoundHttpException(), 'https://apex.example/missing');

        self::assertNull($event->getResponse());
    }

    public function testDoesNotServeOnOtherExceptions(): void
    {
        $event = $this->exceptionEvent($this->filter(), new AccessDeniedHttpException());

        self::assertNull($event->getResponse());
    }

    public function testDoesNotServeOnPost(): void
    {
        $event = $this->exceptionEvent($this->filter(), new NotFoundHttpException(), self::URI, 'POST');

        self::assertNull($event->getResponse());
    }

    public function testDoesNotServeWhenNotConnected(): void
    {
        $event = $this->exceptionEvent($this->filter(connected: false), new NotFoundHttpException());

        self::assertNull($event->getResponse());
    }

    public function testDoesNotServeWhenCacheEmpty(): void
    {
        $event = $this->exceptionEvent($this->filter(cached: false), new NotFoundHttpException());

        self::assertNull($event->getResponse());
    }

    public function testLeavesAnAppReturned404AloneWhenNotAdvertising(): void
    {
        $result = $this->responseEvent($this->filter(cached: false), new Response('Not Found', 404));

        self::assertSame(404, $result->getStatusCode());
        self::assertSame('Not Found', $result->getContent());
    }
}
