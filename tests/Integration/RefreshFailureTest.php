<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Client\FetchResult;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel;

/**
 * A refresh the pool refuses fails loudly in the cron and stays visible in
 * gt:status, though the pool is the one place it cannot be recorded; and an
 * install without reporting still records what the web serves.
 */
final class RefreshFailureTest extends IntegrationTestCase
{
    private const MARKER = '/.well-known/globetrotters-apex-version.json';

    private ?string $locked = null;

    protected function tearDown(): void
    {
        if (null !== $this->locked) {
            self::chmodTree($this->locked, true);
            $this->locked = null;
        }
        parent::tearDown();
    }

    public function testAPoolTheCronCannotWriteIsReportedOutsideIt(): void
    {
        if (\function_exists('posix_geteuid') && 0 === posix_geteuid()) {
            self::markTestSkipped('root writes through any mode');
        }

        $this->bootClient();
        $this->pull('2026-10-14-104728');
        self::assertSame(0, $this->refresh()->getStatusCode());

        $this->locked = $this->poolDir();
        self::chmodTree($this->locked, false);
        $this->pull('2026-10-18-050325');
        $failed = $this->refresh();

        self::assertSame(1, $failed->getStatusCode());
        self::assertStringContainsString('Permission denied', $this->flat($failed->getDisplay()), 'the adapter\'s own reason, not just "Failed to persist"');

        $status = $this->flat($this->runCommand('gt:status')->getDisplay());
        self::assertStringContainsString('[ERROR] The last refresh failed', $status);
        self::assertStringContainsString('2026-10-18-050325', $status, 'the version it tried to install');
        self::assertStringContainsString('Permission denied', $status);
        self::assertStringContainsString('env test', $status);

        // Fixed: the next pull succeeds and the failure is gone.
        self::chmodTree($this->locked, true);
        $this->locked = null;
        self::assertSame(0, $this->refresh()->getStatusCode());
        self::assertStringNotContainsString('The last refresh failed', $this->flat($this->runCommand('gt:status')->getDisplay()));
    }

    public function testAnInstallWithoutReportingStillRecordsWhatTheWebServes(): void
    {
        $client = $this->bootClient();
        $this->pull('2026-10-18-050325');
        $this->refresh();

        $client->request('GET', '/');

        $status = $this->flat($this->runCommand('gt:status')->getDisplay());
        self::assertStringContainsString('Web serving 2026-10-18-050325, seen', $status);
        self::assertStringContainsString('env test', $status);
    }

    private function pull(string $version): void
    {
        $this->serveRequiredFiles();
        $this->fetcher()->on(self::MARKER, FetchResult::http(200, json_encode(['version' => $version], \JSON_THROW_ON_ERROR)));
    }

    private function poolDir(): string
    {
        $kernel = static::$kernel;
        \assert($kernel instanceof TestKernel);

        return $kernel->getProjectDir().'/var/globetrotters-ai-presence/cache';
    }

    private static function chmodTree(string $dir, bool $writable): void
    {
        $paths = [$dir];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            $paths[] = $file->getPathname();
        }
        // Directories last when locking, first when unlocking, so the walk
        // itself never loses access.
        foreach ($writable ? $paths : array_reverse($paths) as $path) {
            $mode = fileperms($path) & 0o7777;
            // Owner write off to lock, owner and group write back to unlock:
            // the pool's own modes are 2770 and 0660.
            chmod($path, $writable ? $mode | 0o220 : $mode & ~0o200);
        }
    }

    private function flat(string $display): string
    {
        return preg_replace('/\s+/', ' ', $display) ?? '';
    }
}
