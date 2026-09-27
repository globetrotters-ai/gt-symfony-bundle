<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\AnalyticsState;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\DroppedCounter;
use Globetrotters\AiPresenceBundle\Analytics\Event;
use Globetrotters\AiPresenceBundle\Analytics\EventBuffer;
use Globetrotters\AiPresenceBundle\Analytics\Flusher;
use Globetrotters\AiPresenceBundle\Analytics\FlushGate;
use Globetrotters\AiPresenceBundle\Analytics\FlushOutcome;
use Globetrotters\AiPresenceBundle\Analytics\IngestResult;
use Globetrotters\AiPresenceBundle\Analytics\NdjsonEventStore;
use Globetrotters\AiPresenceBundle\Analytics\PageViewCounter;
use Globetrotters\AiPresenceBundle\Analytics\PageViewOptions;
use Globetrotters\AiPresenceBundle\Analytics\PageViewRules;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeIngestTransport;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * The ``pageViews`` half of the flush envelope: closed UTC days only, ids
 * minted once at sealing, records deleted only after the endpoint accepts.
 */
final class FlusherPageViewsTest extends TestCase
{
    private const TODAY = '2026-09-27';

    private string $dir;
    private EventBuffer $buffer;
    private PageViewCounter $pageViews;
    private FakeIngestTransport $transport;
    private MockClock $clock;
    private Flusher $flusher;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
        $directory = new BufferDirectory($this->dir);
        $this->buffer = new EventBuffer(new NdjsonEventStore($directory), new DroppedCounter($directory));
        $this->pageViews = new PageViewCounter($directory);
        $this->transport = new FakeIngestTransport();
        $this->clock = new MockClock(self::TODAY.' 09:00:00', 'UTC');

        $this->flusher = new Flusher(
            $this->buffer,
            $this->transport,
            new AnalyticsOptions(true, 'https://api.globetrotters.ai/presence/analytics/server-log', 'token', true, false),
            new AnalyticsState(new ArrayAdapter()),
            new FlushGate($directory, $this->clock),
            $this->clock,
            $this->pageViews,
        );
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    public function testAPageViewOnlyFlushSendsClosedDaysWithNoEvents(): void
    {
        $this->view('2026-09-26', '/visiter/marche', 3);
        $this->view('2026-09-26', '/', 1, PageViewRules::BUCKET_AI_BROWSER);

        self::assertSame(FlushOutcome::Accepted, $this->flusher->run(AnalyticsState::LANE_COMMAND));

        self::assertCount(1, $this->transport->sent);
        $envelope = $this->transport->envelopes()[0];
        self::assertSame([], $envelope['events']);
        self::assertIsArray($envelope['pageViews']);
        $records = $envelope['pageViews'];
        usort($records, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        self::assertSame(['/', '/visiter/marche'], array_column($records, 'path'));
        self::assertSame(['ai_browser', 'browser'], array_column($records, 'bucket'));
        self::assertSame([1, 3], array_column($records, 'count'));
        self::assertSame(['2026-09-26', '2026-09-26'], array_column($records, 'day'));
        self::assertSame(['id', 'day', 'path', 'bucket', 'count'], array_keys($records[0]));
        self::assertSame([], $this->pageViews->pending(10), 'accepted records are deleted');
    }

    public function testTodayIsNeverSent(): void
    {
        $this->view(self::TODAY, '/today', 5);

        $this->flusher->run(AnalyticsState::LANE_COMMAND);

        self::assertArrayNotHasKey('pageViews', $this->transport->envelopes()[0], 'the heartbeat carries no open day');
        self::assertSame(["browser\t/today" => 5], $this->pageViews->counts(self::TODAY));
    }

    public function testAnEnvelopeWithNoPageViewsIsUnchanged(): void
    {
        $this->fill(1);

        $this->flusher->run(AnalyticsState::LANE_COMMAND);

        self::assertArrayNotHasKey('pageViews', $this->transport->envelopes()[0]);
    }

    public function testEventsAndPageViewsShareOneEnvelope(): void
    {
        $this->fill(2);
        $this->view('2026-09-26', '/a', 1);

        $this->flusher->run(AnalyticsState::LANE_COMMAND);

        self::assertCount(1, $this->transport->sent);
        $envelope = $this->transport->envelopes()[0];
        self::assertCount(2, $envelope['events']);
        self::assertCount(1, $envelope['pageViews']);
    }

    public function testARejectedFlushKeepsTheRecordsAndTheRetryResendsTheSameIds(): void
    {
        $this->view('2026-09-26', '/a', 2);
        $this->view('2026-09-25', '/b', 1);
        $this->transport->willReturn(IngestResult::error('Connection timed out'), IngestResult::http(202));

        self::assertSame(FlushOutcome::Rejected, $this->flusher->run(AnalyticsState::LANE_COMMAND));
        self::assertCount(2, $this->pageViews->pending(10), 'nothing is deleted before a 2xx');

        $this->clock->sleep(FlushGate::INTERVAL_SECONDS);
        self::assertSame(FlushOutcome::Accepted, $this->flusher->run(AnalyticsState::LANE_COMMAND));

        $ids = static fn (array $envelope): array => array_column($envelope['pageViews'], 'id');
        [$first, $second] = $this->transport->envelopes();
        self::assertSame($ids($first), $ids($second));
        self::assertSame($this->transport->sent[0]['json'], $this->transport->sent[1]['json']);
        self::assertSame([], $this->pageViews->pending(10));
    }

    public function testADayThatClosesBetweenRetriesDoesNotReidentifyEarlierRecords(): void
    {
        $this->view('2026-09-26', '/a', 1);
        $this->view(self::TODAY, '/b', 1);
        $this->transport->willReturn(IngestResult::http(503), IngestResult::http(202));

        $this->flusher->run(AnalyticsState::LANE_COMMAND);
        $firstIds = array_column($this->transport->envelopes()[0]['pageViews'], 'id');

        // Past midnight: today closes and joins the next batch.
        $this->clock->modify('2026-09-28 00:20:00');
        $this->flusher->run(AnalyticsState::LANE_COMMAND);

        $second = $this->transport->envelopes()[1]['pageViews'];
        self::assertCount(2, $second);
        self::assertSame($firstIds[0], $second[0]['id']);
        self::assertSame(['2026-09-26', self::TODAY], array_column($second, 'day'));
    }

    public function testSendsAtMostTheContractCapPerBatchAndDrainsTheRest(): void
    {
        // Two closed days of 1500 distinct paths each: 3000 records.
        foreach (['2026-09-25', '2026-09-26'] as $day) {
            for ($i = 0; $i < 1500; ++$i) {
                $this->pageViews->increment($day, '/p'.$i, PageViewRules::BUCKET_BROWSER);
            }
        }

        self::assertSame(FlushOutcome::Accepted, $this->flusher->run(AnalyticsState::LANE_COMMAND));

        self::assertCount(2, $this->transport->sent);
        self::assertCount(PageViewCounter::MAX_RECORDS_PER_BATCH, $this->transport->envelopes()[0]['pageViews']);
        self::assertCount(1000, $this->transport->envelopes()[1]['pageViews']);
        self::assertSame([], $this->pageViews->pending(10));
    }

    public function testHalvesPageViewsUnderTheWireCap(): void
    {
        for ($i = 0; $i < 400; ++$i) {
            $this->pageViews->increment('2026-09-26', '/'.str_repeat('x', 400).$i, PageViewRules::BUCKET_BROWSER);
        }
        $this->transport->capAt(64 * 1024);

        $this->flusher->run(AnalyticsState::LANE_COMMAND, maxBatches: 1);

        $sent = \count($this->transport->envelopes()[0]['pageViews']);
        self::assertGreaterThan(0, $sent);
        self::assertLessThan(400, $sent);
        self::assertLessThanOrEqual(64 * 1024, \strlen($this->transport->sent[0]['json']));
        self::assertSame(400 - $sent, $this->pageViews->pendingRecords());
    }

    public function testDaysTheBackendWouldDropAreDeletedNotSent(): void
    {
        $this->view('2026-09-19', '/too-old', 1);
        $this->view('2026-09-20', '/oldest-accepted', 1);

        $this->flusher->run(AnalyticsState::LANE_COMMAND);

        self::assertSame(['/oldest-accepted'], array_column($this->transport->envelopes()[0]['pageViews'], 'path'));
        self::assertFileDoesNotExist($this->dir.'/pageviews-2026-09-19.json');
        self::assertFileDoesNotExist($this->dir.'/pageviews-sealed-2026-09-19.json');
    }

    /**
     * Switching the opt-in off is an opt-out: counts collected while it was on
     * are neither sealed nor sent, and are deleted.
     */
    public function testWithPageViewsDisabledNothingIsSentAndEveryFileIsDiscarded(): void
    {
        $this->view('2026-09-25', '/sealed-earlier', 1);
        $this->pageViews->seal('2026-09-26');
        $this->view('2026-09-26', '/closed', 2);
        $this->view(self::TODAY, '/open', 1);
        $directory = new BufferDirectory($this->dir);
        $options = new AnalyticsOptions(true, 'https://api.globetrotters.ai/presence/analytics/server-log', 'token', true, false);
        $flusher = new Flusher(
            $this->buffer,
            $this->transport,
            $options,
            new AnalyticsState(new ArrayAdapter()),
            new FlushGate($directory, $this->clock),
            $this->clock,
            $this->pageViews,
            new PageViewOptions($options, false, false),
        );

        self::assertSame(FlushOutcome::Accepted, $flusher->run(AnalyticsState::LANE_COMMAND));

        self::assertCount(1, $this->transport->sent, 'the heartbeat still goes');
        self::assertArrayNotHasKey('pageViews', $this->transport->envelopes()[0]);
        self::assertSame([], glob($this->dir.'/pageviews-*') ?: []);
    }

    public function testWithPageViewsEnabledTheyAreSent(): void
    {
        $this->view('2026-09-26', '/closed', 1);
        $directory = new BufferDirectory($this->dir);
        $options = new AnalyticsOptions(true, 'https://api.globetrotters.ai/presence/analytics/server-log', 'token', true, false);
        $flusher = new Flusher(
            $this->buffer,
            $this->transport,
            $options,
            new AnalyticsState(new ArrayAdapter()),
            new FlushGate($directory, $this->clock),
            $this->clock,
            $this->pageViews,
            new PageViewOptions($options, true, false),
        );

        $flusher->run(AnalyticsState::LANE_COMMAND);

        self::assertCount(1, $this->transport->envelopes()[0]['pageViews']);
    }

    private function view(string $day, string $path, int $times, string $bucket = PageViewRules::BUCKET_BROWSER): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->pageViews->increment($day, $path, $bucket);
        }
    }

    private function fill(int $count): void
    {
        foreach (range(1, $count) as $index) {
            $this->buffer->append(new Event(
                \sprintf('00000000-0000-4000-8000-%012d', $index),
                '2026-09-27T08:14:22Z',
                '/llms.txt',
                'ClaudeBot/1.0',
                '160.79.104.10',
                '',
                200,
                4211,
            ));
        }
    }
}
