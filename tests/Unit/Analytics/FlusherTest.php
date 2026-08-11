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
use Globetrotters\AiPresenceBundle\Analytics\IngestResult;
use Globetrotters\AiPresenceBundle\Analytics\IngestTransportInterface;
use Globetrotters\AiPresenceBundle\Analytics\NdjsonEventStore;
use Globetrotters\AiPresenceBundle\GlobetrottersAiPresenceBundle;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeIngestTransport;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class FlusherTest extends TestCase
{
    private const ENDPOINT = 'https://api.globetrotters.ai/presence/analytics/server-log';
    private const TOKEN = 'ingest-token-value';

    private string $dir;
    private EventBuffer $buffer;
    private DroppedCounter $dropped;
    private FakeIngestTransport $transport;
    private AnalyticsState $state;
    private MockClock $clock;
    private Flusher $flusher;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
        $directory = new BufferDirectory($this->dir);
        $this->dropped = new DroppedCounter($directory);
        $this->buffer = new EventBuffer(new NdjsonEventStore($directory), $this->dropped);
        $this->transport = new FakeIngestTransport();
        $this->state = new AnalyticsState(new ArrayAdapter());
        $this->clock = new MockClock('2026-08-11 09:00:00');

        $this->flusher = new Flusher(
            $this->buffer,
            $this->transport,
            new AnalyticsOptions(true, self::ENDPOINT, self::TOKEN, true, false),
            $this->state,
            new FlushGate($directory, $this->clock),
            $this->clock,
        );
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    public function testSendsTheContractEnvelopeAndDrainsTheBuffer(): void
    {
        $this->fill(3);

        self::assertTrue($this->flusher->run(AnalyticsState::LANE_COMMAND));

        self::assertCount(1, $this->transport->sent);
        self::assertSame(self::ENDPOINT, $this->transport->sent[0]['url']);
        self::assertSame(self::TOKEN, $this->transport->sent[0]['token']);

        $envelope = $this->transport->envelopes()[0];
        self::assertSame('symfony-bundle/'.GlobetrottersAiPresenceBundle::VERSION, $envelope['producer']);
        self::assertSame(1.0, $envelope['sampleRate']);
        self::assertSame(0, $envelope['dropped']);
        self::assertCount(3, $envelope['events']);
        self::assertSame(0, $this->buffer->count());
    }

    public function testSampleRateStaysAFloatOnTheWire(): void
    {
        $this->fill(1);
        $this->flusher->run(AnalyticsState::LANE_COMMAND);

        self::assertStringContainsString('"sampleRate":1.0', $this->transport->sent[0]['json']);
    }

    /**
     * The property that makes retry safe. A flush that times out may well have
     * been received; re-sending identical UUIDs cannot double-count because the
     * backend dedupes on id, whereas regenerating them would inflate the
     * customer's own numbers.
     */
    public function testARejectedFlushKeepsEveryEventAndRetriesTheSameUuids(): void
    {
        $this->fill(3);
        $this->transport->willReturn(IngestResult::error('Connection timed out'), IngestResult::http(202));

        self::assertFalse($this->flusher->run(AnalyticsState::LANE_COMMAND));
        self::assertSame(3, $this->buffer->count(), 'nothing is deleted before a 2xx');

        $this->clock->sleep(FlushGate::INTERVAL_SECONDS);
        self::assertTrue($this->flusher->run(AnalyticsState::LANE_COMMAND));

        self::assertSame($this->transport->idsOf(0), $this->transport->idsOf(1));
        self::assertSame($this->transport->sent[0]['json'], $this->transport->sent[1]['json']);
        self::assertSame(0, $this->buffer->count());
    }

    public function testRateLimitingLeavesThePayloadBufferedAndSaysSo(): void
    {
        $this->fill(2);
        $this->transport->willReturn(IngestResult::http(429));

        self::assertFalse($this->flusher->run(AnalyticsState::LANE_COMMAND));

        self::assertSame(2, $this->buffer->count());
        self::assertStringContainsString('rate limiting', (string) $this->state->state()['last_flush_error']);
    }

    public function testAnythingNon2xxIsRetryLaterRatherThanAFailure(): void
    {
        $this->fill(1);
        $this->transport->willReturn(IngestResult::http(500));

        self::assertFalse($this->flusher->run(AnalyticsState::LANE_COMMAND));

        self::assertSame(1, $this->buffer->count());
        self::assertSame(0, (int) $this->state->state()['last_flush_ok']);
        self::assertSame('ingest endpoint returned HTTP 500', $this->state->state()['last_flush_error']);
    }

    public function testSplitsAtTheEventsPerBatchCapAndKeepsTheRemainder(): void
    {
        $this->fill(IngestTransportInterface::MAX_EVENTS_PER_BATCH + 7);

        $this->flusher->run(AnalyticsState::LANE_COMMAND, maxBatches: 1);

        self::assertCount(IngestTransportInterface::MAX_EVENTS_PER_BATCH, $this->transport->envelopes()[0]['events']);
        self::assertSame(7, $this->buffer->count());
    }

    public function testSplitsAtTheDecompressedBodyCap(): void
    {
        // Events fat enough that far fewer than 1000 of them blow past 256KB
        // decompressed, but still inside the 512KB buffer bound.
        $total = 500;
        $this->fill($total, str_repeat('U', Event::MAX_FIELD_CHARS));
        self::assertSame($total, $this->buffer->count(), 'the buffer bound must not interfere with this case');

        $this->flusher->run(AnalyticsState::LANE_COMMAND, maxBatches: 1);

        $sent = \count($this->transport->envelopes()[0]['events']);
        self::assertLessThan($total, $sent);
        self::assertLessThanOrEqual(IngestTransportInterface::MAX_BODY_BYTES, \strlen($this->transport->sent[0]['json']));
        self::assertSame($total - $sent, $this->buffer->count());
    }

    public function testSplitsAtTheCompressedWireCap(): void
    {
        // Gzip in play: the backend applies the tighter 64KB cap to a
        // compressed body, so the transport reports that cap and the flusher
        // has to halve against it rather than against 256KB.
        $this->transport->capAt(IngestTransportInterface::MAX_COMPRESSED_BODY_BYTES, compressionRatio: 4);
        $this->fill(400, str_repeat('U', Event::MAX_FIELD_CHARS));

        $this->flusher->run(AnalyticsState::LANE_COMMAND, maxBatches: 1);

        self::assertLessThanOrEqual(
            IngestTransportInterface::MAX_COMPRESSED_BODY_BYTES,
            $this->transport->wireSize($this->transport->sent[0]['json']),
        );
        self::assertGreaterThan(0, $this->buffer->count());
    }

    public function testDrainsSeveralBatchesInOneRun(): void
    {
        // A backlog must not take an hour to clear: hits older than 90 minutes
        // on arrival are re-stamped and land in the wrong buckets.
        $this->fill(IngestTransportInterface::MAX_EVENTS_PER_BATCH * 2 + 5);

        self::assertTrue($this->flusher->run(AnalyticsState::LANE_COMMAND));

        self::assertCount(3, $this->transport->sent);
        self::assertSame(0, $this->buffer->count());
    }

    public function testStopsDrainingAtTheFirstRejection(): void
    {
        $total = IngestTransportInterface::MAX_EVENTS_PER_BATCH * 2 + 1;
        $this->fill($total);
        self::assertSame($total, $this->buffer->count(), 'the buffer bound must not interfere with this case');
        $this->transport->willReturn(IngestResult::http(202), IngestResult::http(503));

        $this->flusher->run(AnalyticsState::LANE_COMMAND);

        self::assertCount(2, $this->transport->sent, 'a failing endpoint gets one attempt per run, not five');
        self::assertSame($total - IngestTransportInterface::MAX_EVENTS_PER_BATCH, $this->buffer->count());
    }

    public function testReportsBufferOverflowsAndClearsThemOnlyOnAcceptance(): void
    {
        $this->fill(2);
        $this->buffer->discard(['gone-1', 'gone-2']);
        $this->dropped->add(4);
        $this->transport->willReturn(IngestResult::http(500), IngestResult::http(202));

        $this->flusher->run(AnalyticsState::LANE_COMMAND);
        self::assertSame(4, $this->buffer->droppedPending(), 'a rejected envelope does not clear the count');

        $this->clock->sleep(FlushGate::INTERVAL_SECONDS);
        $this->flusher->run(AnalyticsState::LANE_COMMAND);

        self::assertSame(4, $this->transport->envelopes()[1]['dropped']);
        self::assertSame(0, $this->buffer->droppedPending());
    }

    public function testAnEventThatCannotFitIsDiscardedAndCountedRatherThanWedgingTheBuffer(): void
    {
        // Without the escape hatch this event would sit at the head of the
        // buffer and block every later flush forever.
        $this->transport->capAt(10);
        $this->fill(2);

        $this->flusher->run(AnalyticsState::LANE_COMMAND);

        self::assertCount(0, $this->transport->sent);
        self::assertSame(1, $this->buffer->count());
        self::assertSame(1, $this->buffer->droppedPending());
    }

    public function testRecordsWhichLaneFlushedAndTheRunningTotals(): void
    {
        $this->fill(2);

        $this->flusher->run(AnalyticsState::LANE_TERMINATE);

        $state = $this->state->state();
        self::assertSame(AnalyticsState::LANE_TERMINATE, $state['last_flush_lane']);
        self::assertSame(1, $state['flush_count']);
        self::assertSame(2, $state['events_sent']);
        self::assertSame($this->clock->now()->getTimestamp(), $state['last_flush_ok']);
        self::assertSame('', $state['last_flush_error']);
    }

    public function testAnEmptyBufferStillStampsTheAttempt(): void
    {
        self::assertFalse($this->flusher->run(AnalyticsState::LANE_COMMAND));

        self::assertCount(0, $this->transport->sent);
        self::assertSame($this->clock->now()->getTimestamp(), $this->state->state()['last_flush_attempt']);
    }

    public function testDoesNothingWithoutCredentials(): void
    {
        $flusher = new Flusher(
            $this->buffer,
            $this->transport,
            new AnalyticsOptions(true, self::ENDPOINT, '', true, false),
            $this->state,
            new FlushGate(new BufferDirectory($this->dir), $this->clock),
            $this->clock,
        );
        $this->fill(1);

        self::assertFalse($flusher->run(AnalyticsState::LANE_COMMAND));
        self::assertCount(0, $this->transport->sent);
        self::assertSame(1, $this->buffer->count());
    }

    private function fill(int $count, string $ua = 'ClaudeBot/1.0'): void
    {
        foreach (range(1, $count) as $index) {
            $this->buffer->append(new Event(
                \sprintf('00000000-0000-4000-8000-%012d', $index),
                '2026-08-11T09:14:22Z',
                '/llms.txt',
                $ua,
                '160.79.104.10',
                '',
                200,
                4211,
            ));
        }
    }
}
