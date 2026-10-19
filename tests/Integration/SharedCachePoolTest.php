<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Client\FetchResult;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeFetcher;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The CLI that refreshes and the web server that serves are two processes, and
 * the artefacts only reach the apex through a pool both of them read.
 *
 * The application here maps ``cache.app`` to APCu where the CLI can use it,
 * and to the in-memory array adapter otherwise: both are memory private to one
 * process, which is what made a cron'd ``gt:refresh`` write where PHP-FPM never
 * reads. The "CLI" is a second kernel instance with a pool of its own, as a
 * cron run is.
 */
final class SharedCachePoolTest extends IntegrationTestCase
{
    private const MARKER = '/.well-known/globetrotters-apex-version.json';

    /** @var list<KernelInterface> */
    private array $cliKernels = [];

    public static function setUpBeforeClass(): void
    {
        static::$frameworkCache = ['app' => self::processLocalAdapter()];
        static::$cachePool = null;
    }

    public static function tearDownAfterClass(): void
    {
        static::$frameworkCache = [];
        static::$cachePool = null;
    }

    protected function tearDown(): void
    {
        foreach ($this->cliKernels as $kernel) {
            $kernel->shutdown();
        }
        $this->cliKernels = [];
        parent::tearDown();
    }

    public function testARefreshRunFromTheCliIsWhatTheWebServes(): void
    {
        $client = $this->bootClient();

        self::assertSame(0, $this->refreshFromCli('2026-10-14-104728')->getStatusCode());
        $client->request('GET', self::MARKER);
        self::assertStringContainsString('2026-10-14-104728', (string) $client->getResponse()->getContent());

        self::assertSame(0, $this->refreshFromCli('2026-10-18-050325')->getStatusCode());
        $client->request('GET', self::MARKER);
        self::assertStringContainsString(
            '2026-10-18-050325',
            (string) $client->getResponse()->getContent(),
            'the web must serve what the CLI pulled, whatever cache.app is',
        );
    }

    public function testTheDefaultPoolLivesOutsideTheKernelCacheDir(): void
    {
        $this->bootClient();
        $this->refreshFromCli('2026-10-18-050325');

        $kernel = static::$kernel;
        \assert($kernel instanceof KernelInterface);
        $dir = $kernel->getProjectDir().'/var/globetrotters-ai-presence/cache';

        self::assertDirectoryExists($dir);
        self::assertStringStartsNotWith($kernel->getCacheDir(), $dir, 'cache:clear and a deploy must not empty it');
        self::assertNotSame([], self::filesUnder($dir));
    }

    /**
     * The web user and the cron user are often two accounts in one group:
     * every directory the pool creates is group-writable and setgid, and every
     * file group-readable and -writable, whatever the umask, so the other
     * account can read a bundle and replace it.
     */
    public function testThePoolIsSharedWithTheGroup(): void
    {
        $this->bootClient();
        $dir = static::$kernel?->getProjectDir().'/var/globetrotters-ai-presence/cache';

        $umask = umask(0o022);
        try {
            $this->refreshFromCli('2026-10-18-050325');
        } finally {
            umask($umask);
        }

        self::assertSame(0o2770, fileperms($dir) & 0o7777, 'the pool root');
        $files = self::filesUnder($dir);
        self::assertNotSame([], $files);
        foreach ($files as $path) {
            $mode = fileperms($path) & 0o777;
            if (is_dir($path)) {
                self::assertSame(0o070, $mode & 0o070, $path.' must be group rwx');
            } else {
                self::assertSame(0o660, $mode, $path.' must be 0660');
            }
            self::assertSame(0, $mode & 0o007, $path.' must not be world-accessible');
        }
    }

    private function refreshFromCli(string $version): CommandTester
    {
        $kernel = static::createKernel();
        $kernel->boot();
        $this->cliKernels[] = $kernel;

        $fetcher = $kernel->getContainer()->get(FakeFetcher::class);
        \assert($fetcher instanceof FakeFetcher);
        foreach (static::BODIES as $path => $body) {
            $fetcher->on('/'.$path, FetchResult::http(200, $body));
        }
        $fetcher->on(self::MARKER, FetchResult::http(200, json_encode(['version' => $version], \JSON_THROW_ON_ERROR)));

        $tester = new CommandTester((new Application($kernel))->find('gt:refresh'));
        $tester->execute(['--force' => true]);

        return $tester;
    }

    /**
     * @return list<string>
     */
    private static function filesUnder(string $dir): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            $paths[] = $file->getPathname();
        }

        return $paths;
    }

    private static function processLocalAdapter(): string
    {
        return ApcuAdapter::isSupported() && filter_var(\ini_get('apc.enable_cli'), \FILTER_VALIDATE_BOOL)
            ? 'cache.adapter.apcu'
            : 'cache.adapter.array';
    }
}
