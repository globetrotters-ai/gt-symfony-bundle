<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Serving\Breadcrumb;
use Globetrotters\AiPresenceBundle\Serving\BreadcrumbInjector;
use Globetrotters\AiPresenceBundle\Serving\BreadcrumbRenderer;
use Globetrotters\AiPresenceBundle\Settings\BreadcrumbOptions;
use Globetrotters\AiPresenceBundle\Settings\Options;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class BreadcrumbInjectorTest extends TestCase
{
    private const AI_JSON = '{"name":"Nantes","url":"https://www.nantes-tourisme.com","metadata":{"relatedFiles":[{"url":"https://ai.nantes.fr/llms.txt"},{"url":"https://ai.nantes.fr/schema.json"}]}}';

    private const PAGE = '<html><head><title>Nantes</title></head><body><p>Bonjour</p></body></html>';

    private ArtefactCache $cache;
    private Options $options;

    protected function setUp(): void
    {
        $pool = new ArrayAdapter();
        $this->cache = new ArtefactCache($pool);
        $this->cache->store(['ai.json' => self::AI_JSON], 'v1', 0);
        $this->options = new Options($pool, 'https://nantes.globetrotters.ai', 'daily', '/');
    }

    private function injector(
        string $profile = 'subdomain_breadcrumb',
        string $anchorText = '',
    ): BreadcrumbInjector {
        $options = new BreadcrumbOptions($profile, $anchorText);

        return new BreadcrumbInjector(
            $this->options,
            new BreadcrumbRenderer($this->cache, $options),
        );
    }

    private function respond(
        BreadcrumbInjector $injector,
        Response $response,
        string $uri = '/',
        int $type = HttpKernelInterface::MAIN_REQUEST,
    ): Response {
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($uri),
            $type,
            $response,
        );
        $injector->onKernelResponse($event);

        return $event->getResponse();
    }

    private function homepage(BreadcrumbInjector $injector, string $html = self::PAGE): string
    {
        return (string) $this->respond($injector, new Response($html))->getContent();
    }

    public function testInjectsTheHeadBlockBeforeTheClosingHead(): void
    {
        $content = $this->homepage($this->injector());

        self::assertStringContainsString('<link rel="alternate" type="application/ld+json" href="/schema.json">', $content);
        self::assertStringContainsString('<link rel="agent-card" href="/.well-known/agent-card.json">', $content);
        // Only the file the apex bundle lacks names the Globetrotters host.
        self::assertStringContainsString('<link rel="ai-catalog" href="https://ai.nantes.fr/.well-known/ai-catalog.json">', $content);
        self::assertMatchesRegularExpression('~agent-card\.json">\n</head>~', $content);
    }

    /**
     * Installing the bundle must be transparent to the visitor: it may add
     * <link> relations to the head, and nothing a visitor can see. The body is
     * a design this bundle does not own.
     */
    public function testNeverInjectsAnythingVisible(): void
    {
        $content = $this->homepage($this->injector());

        self::assertStringNotContainsString('<a href=', $content);
        self::assertStringContainsString('<body><p>Bonjour</p></body>', $content);
    }

    public function testTouchesNothingAfterTheClosingHead(): void
    {
        $content = $this->homepage($this->injector());
        $body = substr($content, stripos($content, '</head>'));

        self::assertSame('</head><body><p>Bonjour</p></body></html>', $body);
    }

    public function testFullApexProfileInjectsNothing(): void
    {
        self::assertSame(self::PAGE, $this->homepage($this->injector(profile: 'full_apex')));
    }

    public function testNoInjectionOffTheHomepage(): void
    {
        $response = $this->respond($this->injector(), new Response(self::PAGE), '/some-page');

        self::assertSame(self::PAGE, (string) $response->getContent());
    }

    public function testNoInjectionOnASubRequest(): void
    {
        $response = $this->respond($this->injector(), new Response(self::PAGE), '/', HttpKernelInterface::SUB_REQUEST);

        self::assertSame(self::PAGE, (string) $response->getContent());
    }

    public function testNoInjectionOnANonHtmlResponse(): void
    {
        $response = new Response(self::PAGE, 200, ['Content-Type' => 'application/json']);

        self::assertSame(self::PAGE, (string) $this->respond($this->injector(), $response)->getContent());
    }

    public function testNoInjectionOnAnErrorResponse(): void
    {
        $response = new Response(self::PAGE, 500);

        self::assertSame(self::PAGE, (string) $this->respond($this->injector(), $response)->getContent());
    }

    public function testNoInjectionWhenTheCacheIsCold(): void
    {
        $this->cache->clear();

        self::assertSame(self::PAGE, $this->homepage($this->injector()));
    }

    /**
     * The disk backend publishes relative relatedFiles, so no origin can be
     * derived. A breadcrumb to nowhere is worse than none.
     */
    public function testNoInjectionWhenNoOriginCanBeDerived(): void
    {
        $this->cache->store(['ai.json' => '{"metadata":{"relatedFiles":[{"url":"llms.txt"}]}}'], 'v2', 0);

        self::assertSame(self::PAGE, $this->homepage($this->injector()));
    }

    public function testDoesNotDoubleInjectOverAManuallyPlacedHeadBlock(): void
    {
        $twice = $this->homepage($this->injector(), $this->homepage($this->injector()));

        self::assertSame(1, substr_count($twice, 'rel="agent-card"'));
    }

    /**
     * The guard must survive an HTML minifier, which strips comments by
     * default — keying it on the marker comment would double-inject every
     * relation in exactly the case the guard exists for.
     */
    public function testDoesNotDoubleInjectWhenAMinifierStrippedTheMarkerComment(): void
    {
        $placed = Breadcrumb::headBlock('https://ai.nantes.fr');
        $minified = str_replace(Breadcrumb::MARKER."\n", '', $placed);
        $page = '<html><head>'.$minified.'</head><body>x</body></html>';

        $content = $this->homepage($this->injector(), $page);

        self::assertSame(1, substr_count($content, 'rel="ai-catalog"'));
        self::assertSame(1, substr_count($content, 'rel="mcp"'));
    }

    public function testInjectsIntoUppercaseTags(): void
    {
        $content = $this->homepage($this->injector(), '<HTML><HEAD></HEAD><BODY>x</BODY></HTML>');

        self::assertStringContainsString('rel="agent-card"', $content);
    }

    public function testNothingHappensWithoutAHead(): void
    {
        $page = '<html><body>x</body></html>';

        self::assertSame($page, $this->homepage($this->injector(), $page));
    }

    /**
     * Content-Length and ETag describe the pre-injection body; leaving them
     * behind lets a client be handed 304 for a representation we no longer
     * serve.
     */
    public function testInvalidatesBodyMetadataAfterInjecting(): void
    {
        $response = new Response(self::PAGE, 200, [
            'Content-Length' => (string) \strlen(self::PAGE),
            'ETag' => '"abc"',
            'Last-Modified' => 'Mon, 08 Sep 2026 00:00:00 GMT',
        ]);

        $result = $this->respond($this->injector(), $response);

        self::assertFalse($result->headers->has('Content-Length'));
        self::assertFalse($result->headers->has('ETag'));
        self::assertFalse($result->headers->has('Last-Modified'));
    }

    public function testLeavesBodyMetadataAloneWhenNothingWasInjected(): void
    {
        $response = new Response(self::PAGE, 200, ['ETag' => '"abc"']);

        $result = $this->respond($this->injector(profile: 'full_apex'), $response);

        self::assertSame('"abc"', $result->headers->get('ETag'));
    }
}
