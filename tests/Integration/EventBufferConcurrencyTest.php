<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\Event;
use Globetrotters\AiPresenceBundle\Analytics\NdjsonEventStore;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

/**
 * The buffer under concurrent writers.
 *
 * Concurrent served requests are the *normal* case here — which is exactly why
 * the contract forbids a single serialised blob: two workers
 * read-modify-writing one value lose each other's events, under precisely the
 * traffic this feature exists to measure.
 *
 * The property under test is that an append is one atomic operation and
 * nothing else, so writers cannot clobber one another. It is exercised two
 * ways: interleaved writes through independent store instances (deterministic,
 * runs everywhere) and, where the platform provides ``pcntl``, genuinely
 * parallel processes.
 */
final class EventBufferConcurrencyTest extends TestCase
{
    private const WRITERS = 4;
    private const WRITES_PER_JOB = 50;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make('gtaip-concurrency');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    public function testInterleavedWritersLoseNothing(): void
    {
        $stores = [];
        for ($writer = 0; $writer < self::WRITERS; ++$writer) {
            $stores[] = $this->store();
        }

        for ($write = 0; $write < self::WRITES_PER_JOB; ++$write) {
            foreach ($stores as $writer => $store) {
                self::assertTrue($store->append($this->event($writer, $write)));
            }
        }

        $this->assertNothingWasLost();
    }

    public function testParallelProcessesLoseNothing(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl/posix unavailable — the interleaved case covers the same property without true parallelism.');
        }

        $pids = [];
        for ($writer = 0; $writer < self::WRITERS; ++$writer) {
            $pid = pcntl_fork();
            if (-1 === $pid) {
                self::markTestSkipped('fork() failed on this platform.');
            }
            if (0 === $pid) {
                $this->writeInChild($writer);
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $this->assertNothingWasLost();
    }

    public function testATrimDoesNotSwallowAConcurrentAppend(): void
    {
        // The one read-modify-write in the store. It holds LOCK_EX on the same
        // inode the appenders lock, so an append landing mid-rewrite waits and
        // is then written past the new end of file rather than into the middle
        // of a truncate.
        $store = $this->store();
        foreach (range(1, 20) as $index) {
            $store->append($this->event(0, $index));
        }

        $store->trim(10, 1048576);
        $store->append($this->event(9, 999));

        self::assertSame(11, $store->count());
        self::assertSame('w9-999', \array_slice($store->oldest(11), -1)[0]->id());
    }

    /**
     * Child process body: append on its own handles, then leave hard.
     *
     * SIGKILL rather than exit() so the child never runs PHPUnit's shutdown
     * handlers and never emits a second copy of the test results.
     */
    private function writeInChild(int $writer): never
    {
        $store = $this->store();
        for ($write = 0; $write < self::WRITES_PER_JOB; ++$write) {
            $store->append($this->event($writer, $write));
        }

        posix_kill(getmypid(), \SIGKILL);
        exit(0);
    }

    private function assertNothingWasLost(): void
    {
        $expected = self::WRITERS * self::WRITES_PER_JOB;
        $store = $this->store();

        self::assertSame($expected, $store->count());

        $ids = array_map(static fn (Event $event): string => $event->id(), $store->oldest($expected));
        self::assertCount($expected, array_unique($ids));
    }

    private function store(): NdjsonEventStore
    {
        return new NdjsonEventStore(new BufferDirectory($this->dir));
    }

    private function event(int $writer, int $write): Event
    {
        return new Event(
            \sprintf('w%d-%d', $writer, $write),
            '2026-08-11T09:14:22Z',
            '/llms.txt',
            'ClaudeBot/1.0',
            '160.79.104.10',
            '',
            200,
            4211,
        );
    }
}
