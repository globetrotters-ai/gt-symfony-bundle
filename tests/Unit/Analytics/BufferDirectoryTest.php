<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\DroppedCounter;
use Globetrotters\AiPresenceBundle\Analytics\Event;
use Globetrotters\AiPresenceBundle\Analytics\FlushGate;
use Globetrotters\AiPresenceBundle\Analytics\NdjsonEventStore;
use Globetrotters\AiPresenceBundle\Analytics\PageViewCounter;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The web user and the flush's user are often different accounts sharing a
 * group. Under the usual umask of 022 every file would come out readable by
 * the other and nothing more, and the lane running as the second user could
 * neither take the flush lock nor delete what it sent. So every writer gives
 * what it creates the shared mode explicitly. Cross-user access itself cannot
 * be shown here (the suite may run as root); the modes it rests on can.
 */
final class BufferDirectoryTest extends TestCase
{
    private string $parent;
    private string $dir;
    private int $umask;

    protected function setUp(): void
    {
        $this->umask = umask(0o022);
        $this->parent = TempDirectory::make();
        $this->dir = $this->parent.\DIRECTORY_SEPARATOR.'buffer';
    }

    protected function tearDown(): void
    {
        umask($this->umask);
        TempDirectory::remove($this->parent);
    }

    public function testTheDirectoryIsCreatedGroupWritableAndSetgid(): void
    {
        self::assertTrue((new BufferDirectory($this->dir))->ensure());

        self::assertSame(BufferDirectory::DIR_MODE, $this->mode($this->dir));
    }

    public function testAnExistingDirectoryKeepsItsMode(): void
    {
        mkdir($this->dir, 0o750);

        (new BufferDirectory($this->dir))->ensure();

        self::assertSame(0o750, $this->mode($this->dir));
    }

    public function testEveryFileTheLanesCreateIsGroupWritableAndNotWorldReadable(): void
    {
        $directory = new BufferDirectory($this->dir);
        $gate = new FlushGate($directory, new MockClock('2026-10-06 10:00:00', 'UTC'));
        $store = new NdjsonEventStore($directory);
        $pageViews = new PageViewCounter($directory);

        self::assertTrue($store->append(new Event('e1', '2026-10-05T10:00:00Z', '/llms.txt', 'GPTBot', '203.0.113.9', '', 200, 10)));
        (new DroppedCounter($directory))->add(1);
        self::assertTrue($pageViews->increment('2026-10-05', '/', 'human'));
        $gate->withLock(static function () use ($gate, $pageViews): void {
            $gate->stamp();
            $pageViews->seal('2026-10-06');
        });

        $files = array_map('basename', glob($this->dir.'/*') ?: []);
        sort($files);
        self::assertSame(['dropped.json', 'events.ndjson', 'flush.lock', 'flush.stamp', 'pageviews-sealed-2026-10-05.json'], $files);
        foreach ($files as $file) {
            self::assertSame(BufferDirectory::FILE_MODE, $this->mode($this->dir.'/'.$file), $file);
        }
    }

    /**
     * The open day is replaced by rename on every increment, so its mode is
     * the temporary file's, not the first creator's.
     */
    public function testTheOpenPageViewDayStaysShared(): void
    {
        $pageViews = new PageViewCounter(new BufferDirectory($this->dir));

        $pageViews->increment('2026-10-06', '/', 'human');
        $pageViews->increment('2026-10-06', '/a', 'human');

        self::assertSame(BufferDirectory::FILE_MODE, $this->mode($this->dir.'/pageviews-2026-10-06.json'));
    }

    /**
     * A stamp is replaced rather than touched in place: setting an explicit
     * mtime needs ownership, a rename only the directory.
     */
    public function testTheStampIsReplacedAndLeavesNoTemporaryFile(): void
    {
        $directory = new BufferDirectory($this->dir);
        $clock = new MockClock('2026-10-06 10:00:00', 'UTC');
        $gate = new FlushGate($directory, $clock);

        $gate->stamp();
        $clock->sleep(60);
        $gate->stamp();

        self::assertSame($clock->now()->getTimestamp(), $gate->lastAttemptAt());
        self::assertSame(['flush.stamp'], array_map('basename', glob($this->dir.'/*') ?: []));
    }

    /**
     * The shared mode is set once, by the creator. A mode the host chose for
     * an existing file afterwards is theirs to keep.
     */
    public function testAModeTheHostSetIsNotOverwritten(): void
    {
        $directory = new BufferDirectory($this->dir);
        $store = new NdjsonEventStore($directory);
        $event = static fn (string $id): Event => new Event($id, '2026-10-05T10:00:00Z', '/llms.txt', 'GPTBot', '203.0.113.9', '', 200, 10);
        $store->append($event('e1'));
        chmod($store->path(), 0o600);

        $store->append($event('e2'));

        self::assertSame(0o600, $this->mode($store->path()));
    }

    public function testAWritableDirectoryReportsNoUnwritableFiles(): void
    {
        $directory = new BufferDirectory($this->dir);
        (new NdjsonEventStore($directory))->append(new Event('e1', '2026-10-05T10:00:00Z', '/llms.txt', 'GPTBot', '203.0.113.9', '', 200, 10));

        self::assertSame([], $directory->unwritableFiles());
    }

    private function mode(string $path): int
    {
        clearstatcache(true, $path);

        return fileperms($path) & 0o7777;
    }
}
