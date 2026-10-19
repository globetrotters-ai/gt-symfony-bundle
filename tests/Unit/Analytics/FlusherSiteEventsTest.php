<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\AnalyticsState;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\CaptureContext;
use Globetrotters\AiPresenceBundle\Analytics\DroppedCounter;
use Globetrotters\AiPresenceBundle\Analytics\Event;
use Globetrotters\AiPresenceBundle\Analytics\EventBuffer;
use Globetrotters\AiPresenceBundle\Analytics\Flusher;
use Globetrotters\AiPresenceBundle\Analytics\FlushGate;
use Globetrotters\AiPresenceBundle\Analytics\FlushOutcome;
use Globetrotters\AiPresenceBundle\Analytics\IngestResult;
use Globetrotters\AiPresenceBundle\Analytics\IngestTransportInterface;
use Globetrotters\AiPresenceBundle\Analytics\NdjsonEventStore;
use Globetrotters\AiPresenceBundle\Analytics\PageViewOptions;
use Globetrotters\AiPresenceBundle\Analytics\RangesFetch;
use Globetrotters\AiPresenceBundle\Analytics\VendorRanges;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeIngestTransport;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Site events and the capture context on the wire.
 */
final class FlusherSiteEventsTest extends TestCase
{
    private string $dir;
    private EventBuffer $buffer;
    private FakeIngestTransport $transport;
    private AnalyticsState $state;
    private MockClock $clock;
    private CaptureContext $context;
    private VendorRanges $ranges;
    private Flusher $flusher;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
        $directory = new BufferDirectory($this->dir);
        $this->buffer = new EventBuffer(new NdjsonEventStore($directory), new DroppedCounter($directory));
        $this->transport = new FakeIngestTransport();
        $this->state = new AnalyticsState(new ArrayAdapter());
        $this->clock = new MockClock('2026-10-15 10:00:00', 'UTC');
        $options = new AnalyticsOptions(true, VendorRangesTest::ENDPOINT, VendorRangesTest::TOKEN, true, false);
        $this->ranges = new VendorRanges($directory, $this->transport, $options, $this->state, $this->clock);
        $this->context = new CaptureContext($options, $this->state, $this->ranges);

        $this->flusher = new Flusher(
            $this->buffer,
            $this->transport,
            $options,
            $this->state,
            new FlushGate($directory, $this->clock),
            $this->clock,
            context: $this->context,
        );
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    public function testSiteEventsGoOutUnderTheirOwnKeyWithoutAnIp(): void
    {
        $this->buffer->append(new Event('a1', '2026-10-15T09:59:00Z', '/llms.txt', 'GPTBot', '20.171.206.10', '', 200, 10));
        $this->buffer->append(Event::site('s1', '2026-10-15T09:59:30Z', '/visiter', 'ChatGPT-User/1.0', 200, 1234, true, 'chatgpt'));
        $this->buffer->append(Event::site('s2', '2026-10-15T09:59:40Z', '/', '', 301, null, null, 'ignored'));

        self::assertSame(FlushOutcome::Accepted, $this->flusher->run(AnalyticsState::LANE_COMMAND));

        $envelope = $this->transport->envelopes()[0];
        self::assertSame(['a1'], array_column($envelope['events'], 'id'));
        self::assertSame('20.171.206.10', $envelope['events'][0]['ip'], 'artefact events keep their ip');
        self::assertSame([
            ['id' => 's1', 'ts' => '2026-10-15T09:59:30Z', 'path' => '/visiter', 'ua' => 'ChatGPT-User/1.0', 'status' => 200, 'bytes' => 1234, 'verified' => true, 'vendor' => 'chatgpt'],
            ['id' => 's2', 'ts' => '2026-10-15T09:59:40Z', 'path' => '/', 'ua' => null, 'status' => 301, 'bytes' => null, 'verified' => null, 'vendor' => null],
        ], $envelope['siteEvents']);
        foreach ($envelope['siteEvents'] as $site) {
            self::assertArrayNotHasKey('ip', $site);
            self::assertArrayNotHasKey('kind', $site);
        }
        self::assertSame(0, $this->buffer->count());
        self::assertSame(1, $this->state->state()['events_sent']);
        self::assertSame(2, $this->state->state()['site_events_sent']);
    }

    public function testTheHeartbeatCarriesTheCaptureContext(): void
    {
        $this->transport->willReturnRanges(RangesFetch::http(200, VendorRangesTest::LIST));
        $this->ranges->refreshIfDue();
        $this->context->observe(
            Request::create('/', server: ['HTTP_CF_RAY' => '8c1', 'HTTP_VIA' => '1.1 varnish', 'HTTP_SURROGATE_CAPABILITY' => 'symfony="ESI/1.0"']),
            new Response(),
        );

        self::assertSame(FlushOutcome::Accepted, $this->flusher->run(AnalyticsState::LANE_COMMAND));

        $envelope = $this->transport->envelopes()[0];
        self::assertSame([], $envelope['events']);
        self::assertArrayNotHasKey('siteEvents', $envelope);
        self::assertSame([
            'siteCapture' => true,
            'pageViewCounter' => false,
            'pageCache' => 'symfony-http-cache',
            'cdnMarkers' => ['cf-ray', 'via'],
            'rangesVersion' => '3f2a9c1e0b7d',
            'rangesFetchedAt' => '2026-10-15T10:00:00Z',
        ], $envelope['captureContext']);

        // Accepted: the observation window starts over.
        $this->clock->modify('+16 minutes');
        $this->flusher->run(AnalyticsState::LANE_COMMAND);
        $next = $this->transport->envelopes()[1]['captureContext'];
        self::assertSame([], $next['cdnMarkers']);
        self::assertNull($next['pageCache']);
    }

    public function testAFailedFlushKeepsTheMarkers(): void
    {
        $this->context->observe(Request::create('/', server: ['HTTP_X_VARNISH' => '1']));
        $this->transport->willReturn(IngestResult::http(500));

        $this->flusher->run(AnalyticsState::LANE_COMMAND);
        $this->clock->modify('+16 minutes');
        $this->flusher->run(AnalyticsState::LANE_COMMAND);

        self::assertSame(['x-varnish'], $this->transport->envelopes()[1]['captureContext']['cdnMarkers']);
    }

    public function testEveryBatchOfAMultiBatchRunCarriesTheSameContext(): void
    {
        $this->context->observe(Request::create('/', server: ['HTTP_CF_RAY' => '8c1']));
        for ($i = 0; $i <= IngestTransportInterface::MAX_EVENTS_PER_BATCH; ++$i) {
            $this->buffer->append(Event::site('s'.$i, '2026-10-15T09:59:30Z', '/', 'GPTBot', 200, null, null, null));
        }

        self::assertSame(FlushOutcome::Accepted, $this->flusher->run(AnalyticsState::LANE_COMMAND));

        $envelopes = $this->transport->envelopes();
        self::assertCount(2, $envelopes);
        self::assertSame(['cf-ray'], $envelopes[0]['captureContext']['cdnMarkers']);
        self::assertSame(['cf-ray'], $envelopes[1]['captureContext']['cdnMarkers']);
        self::assertSame([], $this->context->toArray()['cdnMarkers'], 'settled once, after the run');
    }

    public function testSettleKeepsAMarkerSeenAfterTheBlockWasTaken(): void
    {
        $this->context->observe(Request::create('/', server: ['HTTP_CF_RAY' => '8c1']));
        $sent = $this->context->toArray();
        $this->context->observe(Request::create('/', server: ['HTTP_X_VARNISH' => '1']));

        $this->context->settle($sent);

        self::assertSame(['x-varnish'], $this->context->toArray()['cdnMarkers']);
    }

    public function testNoRangesAndSiteCaptureOff(): void
    {
        $options = new AnalyticsOptions(true, VendorRangesTest::ENDPOINT, VendorRangesTest::TOKEN, true, false, false);
        $context = new CaptureContext($options, $this->state, $this->ranges);

        self::assertSame([
            'siteCapture' => false,
            'pageViewCounter' => false,
            'pageCache' => null,
            'cdnMarkers' => [],
            'rangesVersion' => null,
            'rangesFetchedAt' => null,
        ], $context->toArray());
    }

    public function testThePageViewCounterFlag(): void
    {
        $options = new AnalyticsOptions(true, VendorRangesTest::ENDPOINT, VendorRangesTest::TOKEN, true, false, false);
        $on = new CaptureContext($options, $this->state, $this->ranges, new PageViewOptions($options, true, false));
        $off = new CaptureContext($options, $this->state, $this->ranges, new PageViewOptions($options, false, false));
        $unconfigured = new AnalyticsOptions(true, VendorRangesTest::ENDPOINT, '', true, false, false);
        $neverRuns = new CaptureContext($unconfigured, $this->state, $this->ranges, new PageViewOptions($unconfigured, true, false));

        self::assertTrue($on->toArray()['pageViewCounter']);
        self::assertFalse($off->toArray()['pageViewCounter']);
        // Opted in but reporting unconfigured: the counter never runs.
        self::assertFalse($neverRuns->toArray()['pageViewCounter']);
    }

    public function testASiteLineSurvivesTheBufferRoundTrip(): void
    {
        $event = Event::site('s1', '2026-10-15T09:59:30Z', '/visiter', 'GPTBot', 200, null, false, null);
        $decoded = json_decode($event->toLine(), true);
        self::assertIsArray($decoded);
        self::assertSame('site', $decoded['kind']);
        self::assertArrayNotHasKey('ip', $decoded);

        self::assertSame($event->toPayload(), Event::fromArray($decoded)->toPayload());
    }
}
