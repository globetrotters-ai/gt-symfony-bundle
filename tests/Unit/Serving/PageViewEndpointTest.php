<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\PageViewCounter;
use Globetrotters\AiPresenceBundle\Analytics\PageViewOptions;
use Globetrotters\AiPresenceBundle\Serving\PageViewBeacon;
use Globetrotters\AiPresenceBundle\Serving\PageViewEndpoint;
use Globetrotters\AiPresenceBundle\Serving\Router;
use Globetrotters\AiPresenceBundle\Tests\Support\IpTrapRequest;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class PageViewEndpointTest extends TestCase
{
    private const HOST = 'www.example.com';
    private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
    private const TODAY = '2026-09-27';

    private string $dir;
    private PageViewCounter $counter;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
        $this->counter = new PageViewCounter(new BufferDirectory($this->dir));
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    public function testRunsAtTheRoutersPriorityAheadOfRoutingAndTheFirewall(): void
    {
        self::assertSame(['onKernelRequest', Router::PRIORITY], PageViewEndpoint::getSubscribedEvents()[KernelEvents::REQUEST]);
    }

    public function testCountsASameOriginBeaconAndAnswers204NoStore(): void
    {
        $event = $this->handle($this->beacon('/visiter/marche/'));

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        foreach (Router::NO_STORE_HEADERS as $name => $value) {
            self::assertSame($value, $response->headers->get($name), $name);
        }
        self::assertSame([], $response->headers->getCookies());
        self::assertTrue($event->isPropagationStopped());
        self::assertTrue($event->getRequest()->attributes->get(Router::ATTRIBUTE_PAGE_VIEW));
        self::assertSame(["browser\t/visiter/marche" => 1], $this->counter->counts(self::TODAY));
    }

    public function testFallsBackToTheRefererWhenThereIsNoOrigin(): void
    {
        $this->handle($this->beacon('/a', origin: null, referer: 'https://'.self::HOST.'/a?utm=x'));

        self::assertSame(["browser\t/a" => 1], $this->counter->counts(self::TODAY));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function foreignOrigins(): iterable
    {
        yield 'another origin' => ['https://evil.example', null];
        yield 'a subdomain' => ['https://sub.'.self::HOST, null];
        yield 'opaque origin' => ['null', null];
        yield 'foreign origin beats a matching referer' => ['https://evil.example', 'https://'.self::HOST.'/'];
        yield 'foreign referer' => [null, 'https://evil.example/'];
        yield 'neither' => [null, null];
        yield 'garbage' => ['not a url', null];
    }

    #[DataProvider('foreignOrigins')]
    public function testDiscardsAnythingThatIsNotSameOrigin(?string $origin, ?string $referer): void
    {
        $event = $this->handle($this->beacon('/a', origin: $origin, referer: $referer));

        self::assertSame(204, $event->getResponse()?->getStatusCode(), 'discarded, but still answered');
        self::assertSame([], $this->counter->counts(self::TODAY));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'not json' => ['p=/a'];
        yield 'no p' => ['{"q":"/a"}'];
        yield 'p not a string' => ['{"p":42}'];
        yield 'list' => ['["/a"]'];
        yield 'query string' => ['{"p":"/a?b=c"}'];
        yield 'protocol-relative' => ['{"p":"//evil.example/"}'];
        yield 'over 1 KB' => ['{"p":"/a","pad":"'.str_repeat('x', 1024).'"}'];
    }

    #[DataProvider('badBodies')]
    public function testDiscardsAnInvalidBody(string $body): void
    {
        $event = $this->handle($this->beacon(body: $body));

        self::assertSame(204, $event->getResponse()?->getStatusCode());
        self::assertSame([], $this->counter->counts(self::TODAY));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function botUserAgents(): iterable
    {
        yield 'empty' => [''];
        yield 'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];
        yield 'headless' => ['Mozilla/5.0 HeadlessChrome/140.0.0.0'];
        yield 'curl' => ['curl/8.7.1'];
    }

    #[DataProvider('botUserAgents')]
    public function testDoesNotCountBots(string $userAgent): void
    {
        $this->handle($this->beacon(userAgent: $userAgent));

        self::assertSame([], $this->counter->counts(self::TODAY));
    }

    public function testBucketsAnAiBrowserSeparately(): void
    {
        $this->handle($this->beacon('/a', userAgent: self::CHROME.' ChatGPT-Agent/1.0'));

        self::assertSame(["ai_browser\t/a" => 1], $this->counter->counts(self::TODAY));
    }

    /**
     * The privacy promise, checked two ways: nothing asks the request for an
     * IP (the trap request throws if anything does), and nothing written to
     * disk carries the IP, the forwarded-for chain or the User-Agent.
     */
    public function testNeverReadsAnIpAndStoresNoUserAgent(): void
    {
        $userAgent = self::CHROME.' UniqueMarker/9.9';
        $request = $this->beacon('/a', userAgent: $userAgent, class: IpTrapRequest::class);
        $request->server->set('REMOTE_ADDR', '203.0.113.77');
        $request->headers->set('X-Forwarded-For', '198.51.100.23');
        $request->headers->set('CF-Connecting-IP', '192.0.2.44');

        $this->handle($request);

        self::assertSame(["browser\t/a" => 1], $this->counter->counts(self::TODAY));
        $written = '';
        foreach (array_diff(scandir($this->dir) ?: [], ['.', '..']) as $file) {
            $written .= file_get_contents($this->dir.'/'.$file);
        }
        self::assertNotSame('', $written);
        foreach (['203.0.113.77', '198.51.100.23', '192.0.2.44', 'UniqueMarker', 'Mozilla'] as $needle) {
            self::assertStringNotContainsString($needle, $written);
        }
    }

    /**
     * Pages cached while the counter was on keep sending the beacon after it
     * is switched off. They get the same cheap 204 rather than falling through
     * to the application's 404/405 — they are just not counted.
     */
    public function testAnswersButDoesNotCountWhenDisabled(): void
    {
        $event = $this->handle($this->beacon('/a'), enabled: false);

        self::assertSame(204, $event->getResponse()?->getStatusCode());
        foreach (Router::NO_STORE_HEADERS as $name => $value) {
            self::assertSame($value, $event->getResponse()->headers->get($name), $name);
        }
        self::assertTrue($event->isPropagationStopped());
        self::assertSame([], $this->counter->counts(self::TODAY));
        self::assertSame([], glob($this->dir.'/*') ?: [], 'nothing written');
    }

    public function testAnswersButDoesNotCountWhenReportingIsNotConfigured(): void
    {
        $event = $this->handle($this->beacon('/a'), configured: false);

        self::assertSame(204, $event->getResponse()?->getStatusCode());
        self::assertSame([], $this->counter->counts(self::TODAY));
    }

    /**
     * A site with ``Referrer-Policy: no-referrer`` sends its beacon with
     * ``Origin: null`` and no Referer. ``null`` is not an origin, and
     * ``Sec-Fetch-Site`` then says where the request came from.
     *
     * @return iterable<string, array{array<string, string>, bool}>
     */
    public static function originSignals(): iterable
    {
        $self = 'https://'.self::HOST;

        yield 'null origin, same-origin fetch' => [['Origin' => 'null', 'Sec-Fetch-Site' => 'same-origin'], true];
        yield 'null origin, cross-site fetch' => [['Origin' => 'null', 'Sec-Fetch-Site' => 'cross-site'], false];
        yield 'null origin, same-site fetch is not same-origin' => [['Origin' => 'null', 'Sec-Fetch-Site' => 'same-site'], false];
        yield 'no origin, same-origin fetch' => [['Sec-Fetch-Site' => 'same-origin'], true];
        yield 'sec-fetch-site outranks the referer' => [['Origin' => 'null', 'Sec-Fetch-Site' => 'cross-site', 'Referer' => $self.'/'], false];
        yield 'null origin, no fetch metadata, same-host referer' => [['Origin' => 'null', 'Referer' => $self.'/a'], true];
        yield 'null origin, no fetch metadata, foreign referer' => [['Origin' => 'null', 'Referer' => 'https://evil.example/'], false];
        yield 'a real origin decides over fetch metadata' => [['Origin' => 'https://evil.example', 'Sec-Fetch-Site' => 'same-origin'], false];
        yield 'a real same origin decides over fetch metadata' => [['Origin' => $self, 'Sec-Fetch-Site' => 'cross-site'], true];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('originSignals')]
    public function testDecidesSameOriginFromOriginThenFetchMetadataThenReferer(array $headers, bool $counted): void
    {
        $request = $this->beacon('/a', origin: null);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $this->handle($request);

        self::assertSame($counted ? ["browser\t/a" => 1] : [], $this->counter->counts(self::TODAY));
    }

    public function testLeavesEveryOtherRequestAlone(): void
    {
        $get = $this->beacon('/a');
        $get->setMethod('GET');
        self::assertNull($this->handle($get)->getResponse(), 'the artefact surface stays GET/HEAD; the counter is POST only');

        $elsewhere = Request::create('https://'.self::HOST.'/.well-known/globetrotters/other', 'POST', content: '{"p":"/a"}');
        self::assertNull($this->handle($elsewhere)->getResponse());

        self::assertNull($this->handle($this->beacon('/a'), main: false)->getResponse());
        self::assertSame([], $this->counter->counts(self::TODAY));
    }

    public function testAnUnwritableBufferStillAnswersQuietly(): void
    {
        file_put_contents($this->dir.'/file', 'x');
        $this->counter = new PageViewCounter(new BufferDirectory($this->dir.'/file/nested'));

        $event = $this->handle($this->beacon('/a'));

        self::assertSame(204, $event->getResponse()?->getStatusCode());
    }

    /**
     * @param class-string<Request> $class
     */
    private function beacon(
        string $path = '/a',
        ?string $origin = 'https://'.self::HOST,
        ?string $referer = null,
        string $userAgent = self::CHROME,
        ?string $body = null,
        string $class = Request::class,
    ): Request {
        $request = $class::create(
            'https://'.self::HOST.PageViewBeacon::PATH,
            'POST',
            server: ['HTTP_USER_AGENT' => $userAgent, 'CONTENT_TYPE' => 'text/plain;charset=UTF-8'],
            content: $body ?? (string) json_encode(['p' => $path]),
        );
        if (null !== $origin) {
            $request->headers->set('Origin', $origin);
        }
        if (null !== $referer) {
            $request->headers->set('Referer', $referer);
        }

        return $request;
    }

    private function handle(Request $request, bool $enabled = true, bool $configured = true, bool $main = true): RequestEvent
    {
        $endpoint = new PageViewEndpoint(
            new PageViewOptions(
                new AnalyticsOptions(true, $configured ? 'https://api.test/ingest' : '', 'token', true, false),
                $enabled,
                false,
            ),
            $this->counter,
            new MockClock(self::TODAY.' 10:00:00', 'UTC'),
        );
        $event = new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            $main ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
        );
        $endpoint->onKernelRequest($event);

        return $event;
    }
}
