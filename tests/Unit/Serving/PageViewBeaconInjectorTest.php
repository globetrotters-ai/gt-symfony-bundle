<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\PageViewOptions;
use Globetrotters\AiPresenceBundle\Serving\PageViewBeacon;
use Globetrotters\AiPresenceBundle\Serving\PageViewBeaconInjector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PageViewBeaconInjectorTest extends TestCase
{
    private const PAGE = '<html><head><title>T</title></head><body><p>Hi</p></body></html>';

    public function testInjectsBeforeTheClosingBodyOnce(): void
    {
        $response = $this->inject(new Response(self::PAGE, 200, ['Content-Type' => 'text/html; charset=utf-8']));

        self::assertSame(
            '<html><head><title>T</title></head><body><p>Hi</p>'.PageViewBeacon::SCRIPT.'</body></html>',
            $response->getContent(),
        );
    }

    public function testAnHtmlResponseWithNoContentTypeYetIsStillHtml(): void
    {
        // Response::prepare() applies the text/html default after kernel.response.
        $response = $this->inject(new Response(self::PAGE));

        self::assertStringContainsString(PageViewBeacon::SCRIPT, (string) $response->getContent());
    }

    public function testUsesTheLastClosingBody(): void
    {
        $page = '<html><body><script>var s="</body>";</script></body></html>';

        $response = $this->inject(new Response($page, 200, ['Content-Type' => 'text/html']));

        self::assertStringEndsWith(PageViewBeacon::SCRIPT.'</body></html>', (string) $response->getContent());
        self::assertSame(1, substr_count((string) $response->getContent(), PageViewBeacon::SCRIPT));
    }

    public function testDoesNotDuplicateAScriptPlacedWithTwig(): void
    {
        $page = '<html><body>'.PageViewBeacon::SCRIPT.'</body></html>';

        $response = $this->inject(new Response($page, 200, ['Content-Type' => 'text/html']));

        self::assertSame($page, $response->getContent());
    }

    /**
     * Idempotence keys on the rendered script, not on the endpoint path: a
     * page that merely mentions the path (a privacy notice linking to it, say)
     * still counts.
     */
    public function testAPageThatMerelyMentionsTheEndpointStillGetsTheScript(): void
    {
        $page = '<html><body><a href="'.PageViewBeacon::PATH.'">'.PageViewBeacon::PATH.'</a></body></html>';

        $response = $this->inject(new Response($page, 200, ['Content-Type' => 'text/html']));

        self::assertSame(1, substr_count((string) $response->getContent(), PageViewBeacon::SCRIPT));
        self::assertStringEndsWith(PageViewBeacon::SCRIPT.'</body></html>', (string) $response->getContent());
    }

    public function testDropsTheStaleEntityTagMetadata(): void
    {
        $response = new Response(self::PAGE, 200, ['Content-Type' => 'text/html']);
        $response->setLastModified(new \DateTimeImmutable('2026-01-01'));

        $this->inject($response);

        self::assertFalse($response->headers->has('Last-Modified'));
    }

    public function testLeavesEverythingElseAlone(): void
    {
        foreach ([
            'json' => new Response('{"a":"</body>"}', 200, ['Content-Type' => 'application/json']),
            'redirect' => new Response('<html><body></body></html>', 302, ['Content-Type' => 'text/html']),
            'error' => new Response('<html><body></body></html>', 500, ['Content-Type' => 'text/html']),
            'no body tag' => new Response('<p>fragment</p>', 200, ['Content-Type' => 'text/html']),
        ] as $case => $response) {
            $before = $response->getContent();
            $this->inject($response);
            self::assertSame($before, $response->getContent(), $case);
        }

        $xhr = Request::create('/');
        $xhr->headers->set('X-Requested-With', 'XMLHttpRequest');
        $response = new Response(self::PAGE, 200, ['Content-Type' => 'text/html']);
        $this->inject($response, request: $xhr);
        self::assertSame(self::PAGE, $response->getContent(), 'xhr');

        $response = new Response(self::PAGE, 200, ['Content-Type' => 'text/html']);
        $this->inject($response, main: false);
        self::assertSame(self::PAGE, $response->getContent(), 'sub-request');

        $file = new BinaryFileResponse(__FILE__, 200, ['Content-Type' => 'text/html']);
        $this->inject($file);
        self::assertFalse($file->getContent());
    }

    public function testIsOptIn(): void
    {
        foreach ([[true, false, true], [false, true, true], [true, true, false]] as [$enabled, $autoInject, $configured]) {
            $response = new Response(self::PAGE, 200, ['Content-Type' => 'text/html']);
            $this->inject($response, $enabled, $autoInject, $configured);
            self::assertSame(self::PAGE, $response->getContent());
        }
    }

    private function inject(
        Response $response,
        bool $enabled = true,
        bool $autoInject = true,
        bool $configured = true,
        ?Request $request = null,
        bool $main = true,
    ): Response {
        $options = new PageViewOptions(
            new AnalyticsOptions(true, $configured ? 'https://api.test/ingest' : '', 'token', true, false),
            $enabled,
            $autoInject,
        );
        (new PageViewBeaconInjector(new PageViewBeacon($options), $options))->onKernelResponse(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request ?? Request::create('/'),
            $main ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
            $response,
        ));

        return $response;
    }
}
