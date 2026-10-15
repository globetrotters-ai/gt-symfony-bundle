<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\AnalyticsState;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\ClientIpResolver;
use Globetrotters\AiPresenceBundle\Analytics\DroppedCounter;
use Globetrotters\AiPresenceBundle\Analytics\EventBuffer;
use Globetrotters\AiPresenceBundle\Analytics\NdjsonEventStore;
use Globetrotters\AiPresenceBundle\Analytics\RangesFetch;
use Globetrotters\AiPresenceBundle\Analytics\SiteCapture;
use Globetrotters\AiPresenceBundle\Analytics\VendorRanges;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeIngestTransport;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SiteCaptureTest extends TestCase
{
    private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';
    private const CHATGPT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot';

    private string $dir;
    private EventBuffer $buffer;
    private FakeIngestTransport $transport;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
        $directory = new BufferDirectory($this->dir);
        $this->buffer = new EventBuffer(new NdjsonEventStore($directory), new DroppedCounter($directory));
        $this->transport = new FakeIngestTransport();
        $this->clock = new MockClock('2026-10-15 10:00:00', 'UTC');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function userAgents(): iterable
    {
        yield 'ChatGPT-User' => [self::CHATGPT, true];
        yield 'GPTBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)', true];
        yield 'Claude-User' => ['Claude-User/1.0', true];
        yield 'curl' => ['curl/8.4.0', true];
        yield 'python requests' => ['python-requests/2.31', true];
        yield 'empty' => ['', true];
        yield 'no browser token' => ['SomeFetcherWithoutMarkers', true];
        yield 'headless chrome' => ['Mozilla/5.0 HeadlessChrome/120.0', true];
        yield 'Chrome' => [self::CHROME, false];
        yield 'Firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', false];
        yield 'Safari iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', false];
    }

    #[DataProvider('userAgents')]
    public function testTheUserAgentGate(string $userAgent, bool $captured): void
    {
        self::assertSame($captured, SiteCapture::isProbableBot($userAgent));
        self::assertSame($captured, $this->capture()->capture($this->request('/visiter', $userAgent), $this->html()));
        self::assertSame($captured ? 1 : 0, $this->buffer->count());
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function scope(): iterable
    {
        yield 'html page' => ['GET', '/visiter/musees', 'text/html; charset=UTF-8', true];
        yield 'HEAD' => ['HEAD', '/visiter', 'text/html', true];
        yield 'POST' => ['POST', '/contact', 'text/html', false];
        yield 'json' => ['GET', '/api/places', 'application/json', false];
        yield 'xml feed' => ['GET', '/feed', 'application/rss+xml', false];
        yield 'no content type' => ['GET', '/visiter', '', false];
        yield 'profiler' => ['GET', '/_profiler/abc123', 'text/html', false];
        yield 'toolbar' => ['GET', '/_wdt/abc123', 'text/html', false];
        yield 'static asset as html' => ['GET', '/assets/app.css', 'text/html', false];
        yield 'image' => ['GET', '/img/logo.PNG', 'text/html', false];
    }

    #[DataProvider('scope')]
    public function testTheScope(string $method, string $path, string $contentType, bool $captured): void
    {
        $request = $this->request($path, 'GPTBot/1.2', $method);
        $response = new Response('<html></html>');
        if ('' === $contentType) {
            $response->headers->remove('Content-Type');
        } else {
            $response->headers->set('Content-Type', $contentType);
        }

        self::assertSame($captured, $this->capture()->capture($request, $response));
    }

    public function testOffWhenTheToggleIsOffOrReportingIsNotConfigured(): void
    {
        $off = new AnalyticsOptions(true, VendorRangesTest::ENDPOINT, VendorRangesTest::TOKEN, true, false, false);
        $unconfigured = new AnalyticsOptions(true, '', '', true, false, true);

        self::assertFalse($this->capture($off)->capture($this->request('/visiter', 'GPTBot'), $this->html()));
        self::assertFalse($this->capture($unconfigured)->capture($this->request('/visiter', 'GPTBot'), $this->html()));
        self::assertSame(0, $this->buffer->count());
    }

    public function testTheStoredLineHasNoIpAndCarriesTheContractFields(): void
    {
        $this->transport->willReturnRanges(RangesFetch::http(200, VendorRangesTest::LIST));
        $ranges = $this->ranges();
        $ranges->refreshIfDue();

        $response = new Response('<html>hello</html>', 404, ['Content-Type' => 'text/html']);
        $this->capture(null, $ranges)->capture($this->request('/visiter/musees/?utm=x', self::CHATGPT, 'GET', '20.171.206.10'), $response);

        $raw = (string) file_get_contents($this->dir.'/'.NdjsonEventStore::FILE);
        self::assertStringNotContainsString('20.171.206.10', $raw);
        self::assertStringNotContainsString('"ip"', $raw);
        self::assertStringNotContainsString('referer', $raw);

        $event = $this->buffer->claim(1)[0];
        $payload = $event->toPayload();
        self::assertTrue($event->isSite());
        self::assertSame(['id', 'ts', 'path', 'ua', 'status', 'bytes', 'verified', 'vendor'], array_keys($payload));
        self::assertSame('/visiter/musees', $payload['path']);
        self::assertSame(404, $payload['status']);
        self::assertSame(\strlen('<html>hello</html>'), $payload['bytes']);
        self::assertTrue($payload['verified']);
        self::assertSame('chatgpt', $payload['vendor']);
        self::assertSame('2026-10-15T10:00:00Z', $payload['ts']);
    }

    public function testVerifiedFalseAndNull(): void
    {
        $this->capture()->capture($this->request('/a', 'GPTBot', 'GET', '8.8.8.8'), $this->html());

        $this->transport->willReturnRanges(RangesFetch::http(200, VendorRangesTest::LIST));
        $ranges = $this->ranges();
        $ranges->refreshIfDue();
        $this->capture(null, $ranges)->capture($this->request('/b', 'GPTBot', 'GET', '8.8.8.8'), $this->html());
        $this->capture(null, $ranges)->capture($this->request('/c', 'ClaudeBot', 'GET', '2600:1f28:365:80b0::9'), $this->html());

        $payloads = array_map(static fn ($event): array => $event->toPayload(), $this->buffer->claim(3));
        self::assertNull($payloads[0]['verified'], 'no list: unverified');
        self::assertNull($payloads[0]['vendor']);
        self::assertFalse($payloads[1]['verified']);
        self::assertNull($payloads[1]['vendor']);
        self::assertTrue($payloads[2]['verified']);
        self::assertSame('claude', $payloads[2]['vendor']);
    }

    public function testBytesAreNullWhenUnknown(): void
    {
        $streamed = new StreamedResponse(static function (): void {}, 200, ['Content-Type' => 'text/html']);
        self::assertNull(SiteCapture::bytes($this->request('/', 'x'), $streamed));
        self::assertNull(SiteCapture::bytes($this->request('/', 'x', 'HEAD'), new Response('', 200)));
        self::assertSame(42, SiteCapture::bytes($this->request('/', 'x', 'HEAD'), new Response('', 200, ['Content-Length' => '42'])));
    }

    private function capture(?AnalyticsOptions $options = null, ?VendorRanges $ranges = null): SiteCapture
    {
        $options ??= $this->options();

        return new SiteCapture($this->buffer, $options, new ClientIpResolver($options), $ranges ?? $this->ranges(), $this->clock);
    }

    private function ranges(): VendorRanges
    {
        return new VendorRanges(new BufferDirectory($this->dir), $this->transport, $this->options(), new AnalyticsState(new ArrayAdapter()), $this->clock);
    }

    private function options(): AnalyticsOptions
    {
        return new AnalyticsOptions(true, VendorRangesTest::ENDPOINT, VendorRangesTest::TOKEN, true, false);
    }

    private function request(string $uri, string $userAgent, string $method = 'GET', string $ip = '203.0.113.7'): Request
    {
        return Request::create($uri, $method, server: ['HTTP_USER_AGENT' => $userAgent, 'REMOTE_ADDR' => $ip]);
    }

    private function html(): Response
    {
        return new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
