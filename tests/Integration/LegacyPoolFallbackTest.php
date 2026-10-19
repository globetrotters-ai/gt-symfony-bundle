<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Client\FetchResult;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Upgrading from 0.9.3 with the config untouched: the bundle's own pool starts
 * empty while the previous default, cache.app, still holds the bundle the site
 * served. The apex keeps serving it, read-only, until the first refresh fills
 * the new pool — rather than 404 until the next cron tick.
 */
final class LegacyPoolFallbackTest extends IntegrationTestCase
{
    private const MARKER = '/.well-known/globetrotters-apex-version.json';

    public static function tearDownAfterClass(): void
    {
        static::$frameworkCache = [];
        static::$cachePool = null;
    }

    protected function setUp(): void
    {
        static::$frameworkCache = [];
        static::$cachePool = null;
    }

    public function testThe093BundleKeepsServingUntilTheFirstRefresh(): void
    {
        $client = $this->bootClient();
        $this->seedLegacy('2026-10-14-104728');

        $client->request('GET', '/llms.txt');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('legacy llms', $client->getResponse()->getContent());
        $client->request('GET', self::MARKER);
        self::assertStringContainsString('2026-10-14-104728', (string) $client->getResponse()->getContent());
        $client->request('GET', '/');
        self::assertStringContainsString('TouristDestination', (string) $client->getResponse()->getContent());

        // The first un-forced cron run is due at once: the new pool is empty.
        $this->serveRequiredFiles();
        $this->fetcher()->on(self::MARKER, FetchResult::http(200, '{"version":"2026-10-18-050325"}'));
        $refresh = $this->runCommand('gt:refresh');
        self::assertStringContainsString('Refreshed to version 2026-10-18-050325', $refresh->getDisplay());

        $client->request('GET', '/llms.txt');
        self::assertSame(static::BODIES['llms.txt'], $client->getResponse()->getContent());
        $client->request('GET', self::MARKER);
        self::assertStringContainsString('2026-10-18-050325', (string) $client->getResponse()->getContent());
    }

    public function testStatusSaysWhereTheBundleComesFrom(): void
    {
        $this->bootClient();
        $this->seedLegacy('2026-10-14-104728');

        $status = preg_replace('/\s+/', ' ', $this->runCommand('gt:status')->getDisplay()) ?? '';

        self::assertStringContainsString('cache.app', $status);
        self::assertStringContainsString('Installed version 2026-10-14-104728', $status);
    }

    public function testAnotherSourcesLegacyBundleIsNeverServed(): void
    {
        $client = $this->bootClient();
        $this->seedLegacy('2026-10-14-104728', 'https://lyon.globetrotters.ai');

        $client->request('GET', '/llms.txt');
        self::assertSame('ANTAGONIST', $client->getResponse()->getContent());
    }

    public function testAnExplicitCachePoolHasNoFallback(): void
    {
        static::$frameworkCache = ['pools' => ['app.presence' => ['adapter' => 'cache.adapter.filesystem']]];
        static::$cachePool = 'app.presence';
        $client = $this->bootClient();
        $this->seedLegacy('2026-10-14-104728');

        $client->request('GET', '/llms.txt');
        self::assertSame('ANTAGONIST', $client->getResponse()->getContent());
    }

    /**
     * cache.app on APCu, where this PHP has no APCu: the fallback must not
     * take the bundle down with it.
     */
    public function testAnUnusableLegacyPoolIsIgnored(): void
    {
        static::$frameworkCache = ['app' => 'cache.adapter.apcu'];
        $client = static::createClient();
        static::getContainer()->get('globetrotters_ai_presence.cache_pool')->clear();
        $this->clearBuffer();

        $client->request('GET', '/llms.txt');
        self::assertSame('ANTAGONIST', $client->getResponse()->getContent());

        $this->serveRequiredFiles();
        self::assertSame(0, $this->refresh()->getStatusCode());
        $client->request('GET', '/llms.txt');
        self::assertSame(static::BODIES['llms.txt'], $client->getResponse()->getContent());
    }

    /**
     * What 0.9.3 left in cache.app: the bundle, written by its ArtefactCache,
     * and the sync state.
     */
    private function seedLegacy(string $version, string $source = TestKernel::WEBSITE_URL): void
    {
        $legacy = static::getContainer()->get('cache.app');
        \assert($legacy instanceof CacheItemPoolInterface);
        $legacy->clear();

        $files = ['llms.txt' => 'legacy llms', 'schema.json' => static::BODIES['schema.json']];
        $files[ltrim(self::MARKER, '/')] = json_encode(['version' => $version], \JSON_THROW_ON_ERROR);
        self::assertTrue((new ArtefactCache($legacy, $source))->store($files, $version, 1_760_000_000));
        (new Options($legacy, $source, 'daily', '/'))->updateState([
            'installed_version' => $version,
            'latest_version' => $version,
            'last_refresh' => time(),
        ]);
    }
}
