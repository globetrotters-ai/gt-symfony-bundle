<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\DroppedCounter;
use Globetrotters\AiPresenceBundle\Analytics\Event;
use Globetrotters\AiPresenceBundle\Analytics\EventBuffer;
use Globetrotters\AiPresenceBundle\Analytics\NdjsonEventStore;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

final class EventBufferTest extends TestCase
{
    private string $dir;
    private NdjsonEventStore $store;
    private DroppedCounter $dropped;
    private EventBuffer $buffer;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
        $directory = new BufferDirectory($this->dir);
        $this->store = new NdjsonEventStore($directory);
        $this->dropped = new DroppedCounter($directory);
        $this->buffer = new EventBuffer($this->store, $this->dropped);
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    public function testAppendsAreClaimedOldestFirst(): void
    {
        $this->buffer->append($this->event('a'));
        $this->buffer->append($this->event('b'));

        self::assertSame(['a', 'b'], array_map(static fn (Event $e): string => $e->id(), $this->buffer->claim(10)));
        self::assertSame(2, $this->buffer->count());
    }

    public function testOverflowDropsOldestAndCountsTheLoss(): void
    {
        // The byte bound is what actually binds in production (a buffered line
        // cannot be under ~147 bytes, so 5000 of them cannot fit in 512KB), so
        // it is the bound driven here — with a payload big enough to cross it
        // without writing 5000 events.
        $bulky = str_repeat('U', Event::MAX_FIELD_CHARS);
        // Same id width as the events actually appended, so the capacity below
        // is exact.
        $perEvent = \strlen($this->event('e00000', $bulky)->toLine()) + 1;
        $fit = intdiv(EventBuffer::MAX_BYTES, $perEvent);

        foreach (range(1, $fit + 5) as $index) {
            $this->buffer->append($this->event(\sprintf('e%05d', $index), $bulky));
        }

        self::assertLessThanOrEqual(EventBuffer::MAX_BYTES, $this->buffer->sizeBytes());
        self::assertSame(5, $this->buffer->droppedPending());
        self::assertSame(5, $this->buffer->droppedTotal());

        $oldest = $this->buffer->claim(1)[0];
        self::assertSame('e00006', $oldest->id(), 'the oldest five went, not the newest');
    }

    public function testPruneBringsAnOverfullBufferInsideBothBoundsAndCountsIt(): void
    {
        // Written straight to the store so no append-time trim intervenes: this
        // is the flush-time prune, catching up with a buffer that grew past the
        // bound. 5000 minimal events is around 1MB, so the byte bound is the
        // one that bites — which is the point of enforcing both in one pass.
        foreach (range(1, EventBuffer::MAX_EVENTS + 3) as $index) {
            $this->store->append($this->event(\sprintf('e%05d', $index)));
        }

        $dropped = $this->buffer->prune();

        self::assertGreaterThan(0, $dropped);
        self::assertSame($dropped, $this->buffer->droppedPending());
        self::assertSame($dropped, $this->buffer->droppedTotal());
        self::assertLessThanOrEqual(EventBuffer::MAX_EVENTS, $this->buffer->count());
        self::assertLessThanOrEqual(EventBuffer::MAX_BYTES, $this->buffer->sizeBytes());
        self::assertSame(EventBuffer::MAX_EVENTS + 3 - $dropped, $this->buffer->count());
    }

    public function testReleaseRemovesAcceptedEvents(): void
    {
        $this->buffer->append($this->event('a'));
        $this->buffer->append($this->event('b'));

        self::assertSame(1, $this->buffer->release(['a']));
        self::assertSame(1, $this->buffer->count());
        self::assertSame(0, $this->buffer->droppedPending(), 'accepted events are not drops');
    }

    public function testDiscardCountsAsADrop(): void
    {
        $this->buffer->append($this->event('a'));

        $this->buffer->discard(['a']);

        self::assertSame(0, $this->buffer->count());
        self::assertSame(1, $this->buffer->droppedPending());
        self::assertSame(1, $this->buffer->droppedTotal());
    }

    public function testSettleDroppedClearsOnlyWhatWasShipped(): void
    {
        $this->buffer->discard(['missing']);
        $this->buffer->append($this->event('a'));
        $this->buffer->discard(['a']);

        $this->buffer->settleDropped(1);

        self::assertSame(0, $this->buffer->droppedPending());
        self::assertSame(1, $this->buffer->droppedTotal());
    }

    private function event(string $id, string $ua = 'ClaudeBot/1.0'): Event
    {
        return new Event($id, '2026-08-11T09:14:22Z', '/llms.txt', $ua, '160.79.104.10', '', 200, 4211);
    }
}
