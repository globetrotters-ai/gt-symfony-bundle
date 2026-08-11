<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\Event;
use Globetrotters\AiPresenceBundle\Analytics\NdjsonEventStore;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

final class NdjsonEventStoreTest extends TestCase
{
    private string $dir;
    private NdjsonEventStore $store;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
        $this->store = new NdjsonEventStore(new BufferDirectory($this->dir));
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    public function testAppendsAndReadsBackInInsertionOrder(): void
    {
        $this->store->append($this->event('a'));
        $this->store->append($this->event('b'));
        $this->store->append($this->event('c'));

        self::assertSame(['a', 'b', 'c'], $this->ids($this->store->oldest(10)));
        self::assertSame(3, $this->store->count());
    }

    public function testOldestHonoursTheLimit(): void
    {
        foreach (range(1, 5) as $index) {
            $this->store->append($this->event('e'.$index));
        }

        self::assertSame(['e1', 'e2'], $this->ids($this->store->oldest(2)));
        self::assertSame([], $this->store->oldest(0));
    }

    public function testDeleteRemovesOnlyTheGivenIds(): void
    {
        foreach (['a', 'b', 'c'] as $id) {
            $this->store->append($this->event($id));
        }

        self::assertSame(2, $this->store->delete(['a', 'c']));
        self::assertSame(['b'], $this->ids($this->store->oldest(10)));
    }

    public function testDeleteIsANoOpForUnknownIds(): void
    {
        $this->store->append($this->event('a'));

        self::assertSame(0, $this->store->delete(['nope']));
        self::assertSame(1, $this->store->count());
    }

    public function testSizeTracksTheFileAndSurvivesRewrites(): void
    {
        $this->store->append($this->event('a'));
        $afterOne = $this->store->sizeBytes();
        $this->store->append($this->event('b'));

        self::assertGreaterThan($afterOne, $this->store->sizeBytes());

        $this->store->delete(['b']);
        self::assertSame($afterOne, $this->store->sizeBytes());
    }

    public function testTrimDropsOldestPastTheEventBound(): void
    {
        foreach (range(1, 10) as $index) {
            $this->store->append($this->event('e'.$index));
        }

        self::assertSame(6, $this->store->trim(4, 1048576));
        self::assertSame(['e7', 'e8', 'e9', 'e10'], $this->ids($this->store->oldest(10)));
    }

    public function testTrimDropsOldestPastTheByteBound(): void
    {
        // Fixed-width ids so every line is the same size and the expected
        // survivor count is exact rather than approximate.
        foreach (range(1, 10) as $index) {
            $this->store->append($this->event(\sprintf('e%02d', $index)));
        }
        $lineBytes = intdiv($this->store->sizeBytes(), 10);

        $dropped = $this->store->trim(5000, $lineBytes * 3);

        self::assertSame(7, $dropped);
        self::assertSame(['e08', 'e09', 'e10'], $this->ids($this->store->oldest(10)));
        self::assertLessThanOrEqual($lineBytes * 3, $this->store->sizeBytes());
    }

    public function testTrimIsANoOpWhenInsideBothBounds(): void
    {
        $this->store->append($this->event('a'));

        self::assertSame(0, $this->store->trim(5000, 524288));
        self::assertSame(1, $this->store->count());
    }

    public function testTrimRemovesCorruptLinesAndCountsThemAsDropped(): void
    {
        // A line that no longer decodes can neither be flushed nor deleted by
        // id, so it would sit at the head of the buffer forever.
        $this->store->append($this->event('a'));
        file_put_contents($this->store->path(), "{not json\n", \FILE_APPEND);
        $this->store->append($this->event('b'));

        self::assertSame(1, $this->store->trim(5000, 524288));
        self::assertSame(['a', 'b'], $this->ids($this->store->oldest(10)));
    }

    public function testDeleteLeavesCorruptLinesForTrimToAccountFor(): void
    {
        $this->store->append($this->event('a'));
        file_put_contents($this->store->path(), "{not json\n", \FILE_APPEND);

        $this->store->delete(['a']);

        self::assertStringContainsString('{not json', (string) file_get_contents($this->store->path()));
    }

    public function testAnUnwritableDirectoryDegradesRatherThanThrows(): void
    {
        $store = new NdjsonEventStore(new BufferDirectory('/proc/gtaip-cannot-exist'));

        self::assertFalse($store->isUsable());
        self::assertFalse($store->append($this->event('a')));
        self::assertSame(0, $store->count());
        self::assertSame(0, $store->sizeBytes());
        self::assertSame([], $store->oldest(10));
        self::assertSame(0, $store->delete(['a']));
        self::assertSame(0, $store->trim(1, 1));
    }

    public function testAnEmptyBufferReadsAsEmpty(): void
    {
        self::assertSame(0, $this->store->count());
        self::assertSame(0, $this->store->sizeBytes());
        self::assertSame([], $this->store->oldest(10));
    }

    private function event(string $id): Event
    {
        return new Event($id, '2026-08-11T09:14:22Z', '/llms.txt', 'ClaudeBot/1.0', '160.79.104.10', '', 200, 4211);
    }

    /**
     * @param list<Event> $events
     *
     * @return list<string>
     */
    private function ids(array $events): array
    {
        return array_map(static fn (Event $event): string => $event->id(), $events);
    }
}
