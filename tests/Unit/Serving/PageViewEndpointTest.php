<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Analytics\AiReferrers;
use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\PageViewCounter;
use Globetrotters\AiPresenceBundle\Analytics\PageViewOptions;
use Globetrotters\AiPresenceBundle\Analytics\VendorRanges;
use Globetrotters\AiPresenceBundle\Serving\PageViewBeacon;
use Globetrotters\AiPresenceBundle\Serving\PageViewEndpoint;
use Globetrotters\AiPresenceBundle\Serving\Router;
use Globetrotters\AiPresenceBundle\Tests\Support\IpTrapRequest;
use Globetrotters\AiPresenceBundle\Tests\Support\OpenRecordingStream;
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
    private AiReferrers $referrers;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
        $this->clock = new MockClock(self::TODAY.' 10:00:00', 'UTC');
        $this->counter = new PageViewCounter(new BufferDirectory($this->dir));
        $this->referrers = new AiReferrers(new BufferDirectory($this->dir), $this->clock);
    }

    protected function tearDown(): void
    {
        OpenRecordingStream::unregister();
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

    public function testCountsAViewReferredByAnAiAnswerUnderItsPlatform(): void
    {
        $this->storeReferrers();

        $this->handle($this->beacon(body: '{"p":"/x","r":"chatgpt.com"}'));
        $this->handle($this->beacon(body: '{"p":"/x","u":"perplexity"}'));
        $this->handle($this->beacon(body: '{"p":"/x","r":"claude.ai","u":"perplexity"}'));
        $this->handle($this->beacon(body: '{"p":"/x","r":"news.example","u":"newsletter"}'));

        self::assertSame([
            "browser@chatgpt\t/x" => 1,
            "browser@perplexity\t/x" => 1,
            "browser@claude\t/x" => 1,
            "browser\t/x" => 1,
        ], $this->counter->counts(self::TODAY));
    }

    /**
     * AC5: the referrer host and the utm_source are matched in memory and
     * forgotten. Only the platform reaches disk — open day and sealed day alike.
     */
    public function testNeverStoresTheReferrerOrTheUtmSource(): void
    {
        $this->storeReferrers();

        $this->handle($this->beacon(body: '{"p":"/x","r":"chatgpt.com"}'));
        $this->handle($this->beacon(body: '{"p":"/x","u":"chatgpt.com"}'));
        $this->handle($this->beacon(body: '{"p":"/x"}'));
        $this->handle($this->beacon(body: '{"p":"/x","r":"unlisted.example","u":"utm_source"}'));

        self::assertSame(["browser@chatgpt\t/x" => 2, "browser\t/x" => 2], $this->counter->counts(self::TODAY));
        $open = (string) file_get_contents($this->dir.'/pageviews-'.self::TODAY.'.json');

        $this->counter->seal('2026-09-28');
        $sealed = (string) file_get_contents($this->dir.'/pageviews-sealed-'.self::TODAY.'.json');

        foreach (['open' => $open, 'sealed' => $sealed] as $kind => $contents) {
            foreach (['chatgpt.com', 'utm_source', 'unlisted.example'] as $needle) {
                self::assertStringNotContainsString($needle, $contents, $kind);
            }
        }
        self::assertSame(['chatgpt', 'none'], array_column($this->counter->pending(10), 'platform'));
        foreach (glob($this->dir.'/pageviews-*') ?: [] as $file) {
            self::assertStringNotContainsString('chatgpt.com', (string) file_get_contents($file), basename($file));
        }
    }

    /**
     * An AI browser's view already says where it came from: it stays in its
     * own bucket as ``none``, and its referrer is not even looked up.
     */
    public function testAnAiBrowserViewIsNeverAttributed(): void
    {
        $this->storeReferrers();
        OpenRecordingStream::register();
        $this->referrers = new AiReferrers(new BufferDirectory(OpenRecordingStream::url($this->dir)), $this->clock);

        $this->handle($this->beacon(userAgent: self::CHROME.' ChatGPT-Agent/1.0', body: '{"p":"/x","r":"chatgpt.com","u":"chatgpt.com"}'));

        self::assertSame(["ai_browser\t/x" => 1], $this->counter->counts(self::TODAY));
        self::assertNotContains(AiReferrers::FILE, OpenRecordingStream::touched());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonStringReferrers(): iterable
    {
        yield 'r a number' => ['{"p":"/x","r":42}'];
        yield 'r a list' => ['{"p":"/x","r":["chatgpt.com"]}'];
        yield 'r an object' => ['{"p":"/x","r":{"host":"chatgpt.com"}}'];
        yield 'u a list' => ['{"p":"/x","u":["chatgpt.com"]}'];
        yield 'both null' => ['{"p":"/x","r":null,"u":null}'];
        yield 'u true' => ['{"p":"/x","u":true}'];
    }

    #[DataProvider('nonStringReferrers')]
    public function testIgnoresAReferrerOrUtmSourceThatIsNotAString(string $body): void
    {
        $this->storeReferrers();

        $this->handle($this->beacon(body: $body));

        self::assertSame(["browser\t/x" => 1], $this->counter->counts(self::TODAY), 'still counted, as none');
    }

    /**
     * AC7: with the synced list gone, the view is still counted — as ``none``.
     */
    public function testWithoutTheListAReferredViewCountsAsNone(): void
    {
        $this->handle($this->beacon(body: '{"p":"/x","r":"chatgpt.com"}'));

        self::assertSame(["browser\t/x" => 1], $this->counter->counts(self::TODAY));
    }

    /**
     * AC12, on the whole endpoint over a recorded buffer_dir holding both
     * synced lists: a plain view reads neither, a referred view reads the
     * small AI list only, and nothing ever opens the vendor ranges.
     */
    public function testReadsTheAiListOnlyWhenThereIsSomethingToMatchAndNeverTheVendorRanges(): void
    {
        $this->storeReferrers();
        file_put_contents($this->dir.'/'.VendorRanges::FILE, '{"version":"3f2a9c1e0b7d","fetched_at":'.$this->clock->now()->getTimestamp().',"ranges":[]}');
        OpenRecordingStream::register();
        $recorded = new BufferDirectory(OpenRecordingStream::url($this->dir));
        $this->counter = new PageViewCounter($recorded);
        $this->referrers = new AiReferrers($recorded, $this->clock);

        $this->handle($this->beacon(body: '{"p":"/x"}'));
        $this->handle($this->beacon(body: '{"p":"/x","r":"","u":""}'));

        self::assertNotSame([], OpenRecordingStream::$opened, 'the control: the counter file was opened through the recorder');
        self::assertNotContains(AiReferrers::FILE, OpenRecordingStream::touched());
        self::assertNotContains(VendorRanges::FILE, OpenRecordingStream::touched());

        $this->handle($this->beacon(body: '{"p":"/x","r":"chatgpt.com"}'));

        self::assertContains(AiReferrers::FILE, OpenRecordingStream::$opened);
        self::assertNotContains(VendorRanges::FILE, OpenRecordingStream::touched());
        self::assertSame(["browser\t/x" => 2, "browser@chatgpt\t/x" => 1], (new PageViewCounter(new BufferDirectory($this->dir)))->counts(self::TODAY));
    }

    /**
     * And structurally: the endpoint cannot reach the vendor ranges at all.
     */
    public function testTheEndpointHasNoWayToTheVendorRanges(): void
    {
        $constructor = (new \ReflectionClass(PageViewEndpoint::class))->getConstructor();
        self::assertNotNull($constructor);
        foreach ($constructor->getParameters() as $parameter) {
            self::assertNotSame(VendorRanges::class, (string) $parameter->getType(), $parameter->getName());
            self::assertNotSame('?'.VendorRanges::class, (string) $parameter->getType(), $parameter->getName());
        }
    }

    private function storeReferrers(): void
    {
        $this->referrers->store('3f2a9c1e0b7d', ['chatgpt.com' => 'chatgpt', 'claude.ai' => 'claude'], ['chatgpt.com' => 'chatgpt', 'perplexity' => 'perplexity']);
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
            $this->clock,
            $this->referrers,
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
