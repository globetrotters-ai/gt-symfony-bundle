<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Sync;

use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Sync\RefreshLock;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

final class RefreshLockTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    public function testASecondRunnerSkipsWhileTheFirstHoldsTheLock(): void
    {
        $this->dir = TempDirectory::make();
        $directory = new BufferDirectory($this->dir);
        $lock = new RefreshLock($directory);

        $inner = 'unset';
        $outer = $lock->withLock(static function () use ($lock, &$inner): string {
            // flock() locks are per open file description, so a second open in
            // this process contends exactly as another process would.
            $inner = $lock->withLock(static fn (): string => 'pulled twice');

            return 'pulled';
        });

        self::assertSame('pulled', $outer);
        self::assertNull($inner);
        self::assertSame('free again', $lock->withLock(static fn (): string => 'free again'));
        self::assertSame(0o660, fileperms($directory->path(RefreshLock::LOCK_FILE)) & 0o777, 'shared with the other lane');
    }

    public function testAnUnusableDirectoryNeverBlocksARefresh(): void
    {
        $this->dir = TempDirectory::make();
        $file = $this->dir.'/not-a-directory';
        touch($file);
        $lock = new RefreshLock(new BufferDirectory($file.'/presence'));

        self::assertSame('pulled', $lock->withLock(static fn (): string => 'pulled'));
    }
}
