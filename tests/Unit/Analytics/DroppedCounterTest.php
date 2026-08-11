<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\DroppedCounter;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

final class DroppedCounterTest extends TestCase
{
    private string $dir;
    private DroppedCounter $counter;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
        $this->counter = new DroppedCounter(new BufferDirectory($this->dir));
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    public function testStartsAtZero(): void
    {
        self::assertSame(['pending' => 0, 'total' => 0], $this->counter->read());
    }

    public function testAddAccumulatesBothCounters(): void
    {
        $this->counter->add(3);
        $this->counter->add(2);

        self::assertSame(['pending' => 5, 'total' => 5], $this->counter->read());
    }

    public function testSettleSubtractsPendingAndKeepsTheLifetimeTotal(): void
    {
        $this->counter->add(5);
        $this->counter->settle(5);

        self::assertSame(['pending' => 0, 'total' => 5], $this->counter->read());
    }

    public function testSettleOnlyClearsWhatTheEnvelopeReported(): void
    {
        // Drops that happened while a flush was in flight still need reporting,
        // so settling subtracts the shipped figure rather than zeroing.
        $this->counter->add(4);
        $shipped = $this->counter->pending();
        $this->counter->add(3);

        $this->counter->settle($shipped);

        self::assertSame(3, $this->counter->pending());
        self::assertSame(7, $this->counter->total());
    }

    public function testSettleNeverGoesNegative(): void
    {
        $this->counter->add(1);
        $this->counter->settle(9);

        self::assertSame(0, $this->counter->pending());
    }

    public function testNonPositiveMutationsAreIgnored(): void
    {
        $this->counter->add(0);
        $this->counter->add(-4);
        $this->counter->settle(0);

        self::assertSame(['pending' => 0, 'total' => 0], $this->counter->read());
    }

    public function testCorruptStateReadsAsZeroRatherThanThrowing(): void
    {
        file_put_contents($this->dir.'/'.DroppedCounter::FILE, 'not json');

        self::assertSame(['pending' => 0, 'total' => 0], $this->counter->read());

        $this->counter->add(2);
        self::assertSame(2, $this->counter->pending());
    }

    public function testAnUnwritableDirectoryDegradesRatherThanThrows(): void
    {
        $counter = new DroppedCounter(new BufferDirectory('/proc/gtaip-cannot-exist'));
        $counter->add(3);

        self::assertSame(0, $counter->pending());
    }
}
