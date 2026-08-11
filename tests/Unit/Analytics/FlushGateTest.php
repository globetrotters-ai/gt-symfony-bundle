<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\FlushGate;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class FlushGateTest extends TestCase
{
    private string $dir;
    private MockClock $clock;
    private FlushGate $gate;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
        $this->clock = new MockClock('2026-08-11 09:00:00');
        $this->gate = new FlushGate(new BufferDirectory($this->dir), $this->clock);
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    public function testAFreshInstallIsDueImmediately(): void
    {
        self::assertNull($this->gate->lastAttemptAt());
        self::assertTrue($this->gate->isDue());
    }

    public function testStampingHoldsTheNextFlushForTheFullInterval(): void
    {
        $this->gate->stamp();

        self::assertSame($this->clock->now()->getTimestamp(), $this->gate->lastAttemptAt());
        self::assertFalse($this->gate->isDue());

        $this->clock->sleep(FlushGate::INTERVAL_SECONDS - 1);
        self::assertFalse($this->gate->isDue());

        $this->clock->sleep(1);
        self::assertTrue($this->gate->isDue());
    }

    public function testEveryLaneSharesTheOneStamp(): void
    {
        // A cron'd command that has just flushed must leave the opportunistic
        // kernel.terminate lane dormant, and vice versa.
        $other = new FlushGate(new BufferDirectory($this->dir), $this->clock);
        $other->stamp();

        self::assertFalse($this->gate->isDue());
    }

    public function testTheLockIsExclusiveAndNonBlocking(): void
    {
        $inner = null;

        $outer = $this->gate->withLock(function () use (&$inner): string {
            $inner = $this->gate->withLock(static fn (): string => 'reentered');

            return 'held';
        });

        self::assertSame('held', $outer);
        self::assertNull($inner, 'a second flush skips rather than queueing behind a 20-second HTTP call');
    }

    public function testTheLockIsReleasedAfterwards(): void
    {
        $this->gate->withLock(static fn (): string => 'first');

        self::assertSame('second', $this->gate->withLock(static fn (): string => 'second'));
    }

    public function testTheLockIsReleasedWhenTheWorkThrows(): void
    {
        try {
            $this->gate->withLock(static fn () => throw new \RuntimeException('boom'));
            self::fail('the exception should propagate to the caller');
        } catch (\RuntimeException) {
        }

        self::assertSame('after', $this->gate->withLock(static fn (): string => 'after'));
    }

    public function testAnUnwritableDirectoryDegradesRatherThanThrows(): void
    {
        $gate = new FlushGate(new BufferDirectory('/proc/gtaip-cannot-exist'), $this->clock);

        $gate->stamp();

        self::assertNull($gate->lastAttemptAt());
        self::assertNull($gate->withLock(static fn (): string => 'never'));
    }
}
