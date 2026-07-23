<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Serving\HeadInjector;
use Globetrotters\AiPresenceBundle\Settings\Options;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class HeadInjectorTest extends TestCase
{
    private const SCHEMA = '{"@context":"https://schema.org","@type":"TouristDestination"}';

    private ArtefactCache $cache;
    private HeadInjector $injector;

    protected function setUp(): void
    {
        $pool = new ArrayAdapter();
        $this->cache = new ArtefactCache($pool);
        $this->cache->store(['schema.json' => self::SCHEMA], 'v1', 0);
        $this->injector = new HeadInjector($this->cache, new Options($pool, 'https://nantes.globetrotters.ai', 'daily', '/'));
    }

    private function respond(string $uri, Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($uri),
            $type,
            $response,
        );
        $this->injector->onKernelResponse($event);

        return $event;
    }

    public function testRenderBuildsScriptTag(): void
    {
        self::assertSame(
            '<script type="application/ld+json">{"@context":"https:\/\/schema.org"}</script>'."\n",
            HeadInjector::render('{"@context":"https://schema.org"}'),
        );
    }

    public function testRenderEscapesScriptBreakout(): void
    {
        $markup = HeadInjector::render('{"name":"</script><script>alert(1)</script>"}');

        self::assertSame(1, substr_count($markup, '</script>'));
        self::assertStringContainsString('<\/script>', $markup);
    }

    public function testRenderDropsInvalidJson(): void
    {
        self::assertSame('', HeadInjector::render('not json'));
        self::assertSame('', HeadInjector::render(''));
        self::assertSame('', HeadInjector::render('   '));
    }

    public function testInjectsBeforeHeadCloseOnHomepage(): void
    {
        $response = new Response('<html><head><title>t</title></head><body></body></html>');
        $this->respond('/', $response);

        $content = (string) $response->getContent();
        self::assertStringContainsString('<script type="application/ld+json">', $content);
        self::assertMatchesRegularExpression('~</script>\n</head>~', $content);
    }

    public function testCaseInsensitiveHeadClose(): void
    {
        $response = new Response('<HTML><HEAD></HEAD><BODY></BODY></HTML>');
        $this->respond('/', $response);

        self::assertStringContainsString('application/ld+json', (string) $response->getContent());
    }

    public function testRemovesStaleContentLength(): void
    {
        $response = new Response('<html><head></head><body></body></html>');
        $response->headers->set('Content-Length', '38');
        $this->respond('/', $response);

        self::assertFalse($response->headers->has('Content-Length'));
    }

    public function testSkipsNonHomepagePath(): void
    {
        $response = new Response('<html><head></head></html>');
        $this->respond('/about', $response);

        self::assertStringNotContainsString('ld+json', (string) $response->getContent());
    }

    public function testSkipsNon200(): void
    {
        $response = new Response('<html><head></head></html>', 404);
        $this->respond('/', $response);

        self::assertStringNotContainsString('ld+json', (string) $response->getContent());
    }

    public function testSkipsNonHtmlContentType(): void
    {
        $response = new Response('{"json":true}', 200, ['Content-Type' => 'application/json']);
        $this->respond('/', $response);

        self::assertStringNotContainsString('ld+json', (string) $response->getContent());
    }

    public function testSkipsSubRequest(): void
    {
        $response = new Response('<html><head></head></html>');
        $this->respond('/', $response, HttpKernelInterface::SUB_REQUEST);

        self::assertStringNotContainsString('ld+json', (string) $response->getContent());
    }

    public function testSkipsWhenSchemaNotCached(): void
    {
        $pool = new ArrayAdapter();
        $injector = new HeadInjector(new ArtefactCache($pool), new Options($pool, 'https://x.example', 'daily', '/'));
        $response = new Response('<html><head></head></html>');
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/'),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
        $injector->onKernelResponse($event);

        self::assertStringNotContainsString('ld+json', (string) $response->getContent());
    }

    public function testSkipsWhenNoHeadClose(): void
    {
        $response = new Response('plain text page');
        $this->respond('/', $response);

        self::assertSame('plain text page', $response->getContent());
    }

    public function testIdempotentWhenMarkupAlreadyPresent(): void
    {
        $markup = HeadInjector::render(self::SCHEMA);
        $response = new Response('<html><head>'.$markup.'</head></html>');
        $this->respond('/', $response);

        self::assertSame(1, substr_count((string) $response->getContent(), 'application/ld+json'));
    }

    public function testIdempotentWhenExistingTagNewlineStripped(): void
    {
        // A minifier/Twig trim can drop the trailing newline render() appends;
        // the guard must still recognise the tag and not inject a second copy.
        $tag = rtrim(HeadInjector::render(self::SCHEMA), "\n");
        $response = new Response('<html><head>'.$tag.'</head></html>');
        $this->respond('/', $response);

        self::assertSame(1, substr_count((string) $response->getContent(), 'application/ld+json'));
    }

    public function testCustomHomepagePath(): void
    {
        $pool = new ArrayAdapter();
        $cache = new ArtefactCache($pool);
        $cache->store(['schema.json' => self::SCHEMA], 'v1', 0);
        $injector = new HeadInjector($cache, new Options($pool, 'https://x.example', 'daily', '/en'));

        $response = new Response('<html><head></head></html>');
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/en'),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
        $injector->onKernelResponse($event);

        self::assertStringContainsString('ld+json', (string) $response->getContent());
    }
}
