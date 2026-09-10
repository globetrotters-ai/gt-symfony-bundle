<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Serving\Sitemap;
use Globetrotters\AiPresenceBundle\Serving\SitemapFallback;
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

final class SitemapFallbackTest extends TestCase
{
    private const BASE_URL = 'https://nantes.globetrotters.ai';

    private const URI = 'https://apex.example/sitemap.xml';

    private function fallback(bool $connected = true, bool $cached = true): SitemapFallback
    {
        $pool = new ArrayAdapter();
        $cache = new ArtefactCache($pool);
        if ($cached) {
            $cache->store(['llms.txt' => 'x'], 'v1', 0);
        }
        $options = new Options($pool, $connected ? self::BASE_URL : '', 'daily', '/');

        return new SitemapFallback($options, $cache, new Sitemap($options, $cache));
    }

    private function responseEvent(SitemapFallback $fallback, Response $response, string $uri = self::URI, string $method = 'GET'): Response
    {
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($uri, $method),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
        $fallback->onKernelResponse($event);

        return $event->getResponse();
    }

    private function exceptionEvent(SitemapFallback $fallback, \Throwable $throwable, string $uri = self::URI, string $method = 'GET'): ExceptionEvent
    {
        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($uri, $method),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
        $fallback->onKernelException($event);

        return $event;
    }

    public function testServesAGeneratedSitemapWhenTheAppThrows404(): void
    {
        $event = $this->exceptionEvent($this->fallback(), new NotFoundHttpException());

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(Sitemap::CONTENT_TYPE, $response->headers->get('Content-Type'));
        self::assertStringContainsString('<loc>https://apex.example/llms.txt</loc>', (string) $response->getContent());
    }

    public function testServesAGeneratedSitemapWhenTheAppReturnsAnExplicit404Response(): void
    {
        $generated = $this->responseEvent($this->fallback(), new Response('Not Found', 404, ['Content-Type' => 'text/html']));

        self::assertSame(200, $generated->getStatusCode());
        self::assertSame(Sitemap::CONTENT_TYPE, $generated->headers->get('Content-Type'));
        self::assertStringContainsString('<urlset', (string) $generated->getContent());
    }

    /**
     * The decision this class exists to express: an application-served sitemap
     * is a manifest of that application's site and the bundle does not touch
     * it — not even to append the discovery URLs.
     */
    public function testLeavesAnApplicationServedSitemapExactlyAsItIs(): void
    {
        $app = '<?xml version="1.0" encoding="UTF-8"?><urlset><url><loc>https://apex.example/about</loc></url></urlset>';
        $response = $this->responseEvent($this->fallback(), new Response($app, 200, ['Content-Type' => Sitemap::CONTENT_TYPE]));

        self::assertSame($app, $response->getContent());
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * The body embeds the origin the request arrived on, so it must never be
     * reused for a host alias sharing a cache entry.
     */
    public function testTheGeneratedResponseIsNotCacheable(): void
    {
        $response = $this->exceptionEvent($this->fallback(), new NotFoundHttpException())->getResponse();

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
        $generated = $this->responseEvent($this->fallback(), new Response('', 404), method: 'HEAD');

        self::assertSame(200, $generated->getStatusCode());
        self::assertSame(Sitemap::CONTENT_TYPE, $generated->headers->get('Content-Type'));
        self::assertSame('', $generated->getContent());
    }

    public function testStaleBodyMetadataIsDropped(): void
    {
        $response = new Response('Not Found', 404, ['Content-Type' => 'text/html', 'Content-Length' => '9']);
        $this->responseEvent($this->fallback(), $response);

        self::assertFalse($response->headers->has('Content-Length'));
    }

    public function testDoesNotServeOnOtherPaths(): void
    {
        $event = $this->exceptionEvent($this->fallback(), new NotFoundHttpException(), 'https://apex.example/missing');

        self::assertNull($event->getResponse());
    }

    public function testDoesNotServeOnOtherExceptions(): void
    {
        $event = $this->exceptionEvent($this->fallback(), new AccessDeniedHttpException());

        self::assertNull($event->getResponse());
    }

    public function testDoesNotServeOnPost(): void
    {
        $event = $this->exceptionEvent($this->fallback(), new NotFoundHttpException(), self::URI, 'POST');

        self::assertNull($event->getResponse());
    }

    public function testDoesNotServeWhenNotConnected(): void
    {
        $event = $this->exceptionEvent($this->fallback(connected: false), new NotFoundHttpException());

        self::assertNull($event->getResponse());
    }

    public function testDoesNotServeWhenCacheEmpty(): void
    {
        $event = $this->exceptionEvent($this->fallback(cached: false), new NotFoundHttpException());

        self::assertNull($event->getResponse());
    }

    public function testLeavesAnAppReturned404AloneWhenNotAdvertising(): void
    {
        $result = $this->responseEvent($this->fallback(cached: false), new Response('Not Found', 404));

        self::assertSame(404, $result->getStatusCode());
        self::assertSame('Not Found', $result->getContent());
    }
}
