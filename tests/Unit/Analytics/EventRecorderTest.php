<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\AnalyticsState;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\ClientIpResolver;
use Globetrotters\AiPresenceBundle\Analytics\DroppedCounter;
use Globetrotters\AiPresenceBundle\Analytics\Event;
use Globetrotters\AiPresenceBundle\Analytics\EventBuffer;
use Globetrotters\AiPresenceBundle\Analytics\EventRecorder;
use Globetrotters\AiPresenceBundle\Analytics\NdjsonEventStore;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;

final class EventRecorderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    public function testRecordsTheContractFieldsFromTheRequest(): void
    {
        $buffer = $this->buffer();
        $recorder = $this->recorder($buffer);

        $recorder->record($this->request([
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
            'HTTP_REFERER' => 'https://example.test/',
            'REMOTE_ADDR' => '160.79.104.10',
        ]), '/llms.txt', 200, 4211);

        $event = $buffer->claim(1)[0];
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $event->id());
        self::assertSame('/llms.txt', $event->path());
        self::assertSame('Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', $event->ua());
        self::assertSame('https://example.test/', $event->referer());
        self::assertSame('160.79.104.10', $event->ip());
        self::assertSame(200, $event->status());
        self::assertSame(4211, $event->bytes());
    }

    /**
     * The single most likely thing to get silently wrong: a bare timestamp is
     * read as UTC by the backend, so an app on Europe/Paris would report every
     * hit two hours off — and the backend re-stamps anything more than 90
     * minutes stale rather than rejecting it, so nothing anywhere errors.
     */
    public function testTimestampIsUtcWithAnExplicitOffsetOnANonUtcApplication(): void
    {
        $buffer = $this->buffer();
        $recorder = $this->recorder(
            $buffer,
            clock: new MockClock(new \DateTimeImmutable('2026-08-11 11:14:22', new \DateTimeZone('Europe/Paris'))),
        );

        $recorder->record($this->request(), '/llms.txt', 200, 10);

        self::assertSame('2026-08-11T09:14:22Z', $buffer->claim(1)[0]->ts());
    }

    public function testTimestampIsRealUtcUnderADefaultTimezoneChange(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');

        try {
            $buffer = $this->buffer();
            $this->recorder($buffer, clock: new NativeClock())->record($this->request(), '/ai.json', 200, 10);

            $ts = $buffer->claim(1)[0]->ts();
        } finally {
            date_default_timezone_set($previous);
        }

        self::assertStringEndsWith('Z', $ts);
        // Within a second of real UTC, not of the server's wall clock.
        self::assertLessThanOrEqual(
            1,
            abs((new \DateTimeImmutable($ts))->getTimestamp() - time()),
            \sprintf('%s is not UTC', $ts),
        );
    }

    public function testCapturesNothingWithoutIngestCredentials(): void
    {
        $buffer = $this->buffer();
        $recorder = $this->recorder($buffer, options: new AnalyticsOptions(true, '', '', true, false));

        $recorder->record($this->request(), '/llms.txt', 200, 10);

        self::assertFalse($recorder->isCapturing());
        self::assertSame(0, $buffer->count());
    }

    public function testCapturesNothingWhenReportingIsDisabled(): void
    {
        $buffer = $this->buffer();
        $recorder = $this->recorder($buffer, options: new AnalyticsOptions(false, 'https://api.test/ingest', 'token', true, false));

        $recorder->record($this->request(), '/llms.txt', 200, 10);

        self::assertSame(0, $buffer->count());
    }

    public function testAFailingBufferNeverEscapesToTheServePath(): void
    {
        $directory = new BufferDirectory($this->dir);
        $buffer = new EventBuffer(new ThrowingEventStore(), new DroppedCounter($directory));

        $this->recorder($buffer)->record($this->request(), '/llms.txt', 200, 10);

        // Reaching here at all is the assertion: capture must not affect a
        // served response on any code path, including failure paths.
        self::addToAssertionCount(1);
    }

    public function testRecordsThatClientIpResolutionIsUntrustworthy(): void
    {
        $state = new AnalyticsState(new ArrayAdapter());
        $buffer = $this->buffer();

        $this->recorder($buffer, state: $state)->record(
            $this->request(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '160.79.104.10']),
            '/llms.txt',
            200,
            10,
        );

        self::assertTrue($state->state()['ip_observed']);
        self::assertFalse($state->state()['ip_trustworthy']);
    }

    private function buffer(): EventBuffer
    {
        $directory = new BufferDirectory($this->dir);

        return new EventBuffer(new NdjsonEventStore($directory), new DroppedCounter($directory));
    }

    private function recorder(
        EventBuffer $buffer,
        ?AnalyticsOptions $options = null,
        ?AnalyticsState $state = null,
        ?\Symfony\Component\Clock\ClockInterface $clock = null,
    ): EventRecorder {
        $options ??= new AnalyticsOptions(true, 'https://api.test/ingest', 'token', true, false);

        return new EventRecorder(
            $buffer,
            $options,
            new ClientIpResolver($options),
            $state ?? new AnalyticsState(new ArrayAdapter()),
            $clock ?? new MockClock('2026-08-11 09:14:22'),
        );
    }

    /**
     * @param array<string, string> $server
     */
    private function request(array $server = ['REMOTE_ADDR' => '160.79.104.10']): Request
    {
        return new Request([], [], [], [], [], $server);
    }
}

/**
 * A store that fails the way a full disk or a revoked permission would.
 */
final class ThrowingEventStore implements \Globetrotters\AiPresenceBundle\Analytics\EventStoreInterface
{
    public function append(Event $event): bool
    {
        throw new \RuntimeException('disk on fire');
    }

    public function sizeBytes(): int
    {
        return 0;
    }

    public function count(): int
    {
        return 0;
    }

    public function trim(int $maxEvents, int $maxBytes): int
    {
        return 0;
    }

    public function oldest(int $limit): array
    {
        return [];
    }

    public function delete(array $ids): int
    {
        return 0;
    }

    public function isUsable(): bool
    {
        return true;
    }
}
