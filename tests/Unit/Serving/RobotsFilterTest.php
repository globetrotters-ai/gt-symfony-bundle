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

    public function testBuildBlockContainsAllBotsAndSitemap(): void
    {
        $block = RobotsFilter::buildBlock(self::BASE_URL);

        $expected = "# Globetrotters AI Presence\n"
            ."User-agent: GPTBot\nAllow: /\n"
            ."User-agent: ChatGPT-User\nAllow: /\n"
            ."User-agent: OAI-SearchBot\nAllow: /\n"
            ."User-agent: ClaudeBot\nAllow: /\n"
            ."User-agent: Claude-User\nAllow: /\n"
            ."User-agent: Anthropic-AI\nAllow: /\n"
            ."User-agent: PerplexityBot\nAllow: /\n"
            ."User-agent: Google-Extended\nAllow: /\n"
            ."User-agent: Applebot-Extended\nAllow: /\n"
            ."User-agent: CCBot\nAllow: /\n"
            ."User-agent: meta-externalagent\nAllow: /\n"
            ."\n"
            .'Sitemap: '.self::BASE_URL."/sitemap.xml\n";

        self::assertSame($expected, $block);
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
        self::assertStringContainsString('Sitemap: '.self::BASE_URL.'/sitemap.xml', $content);
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
