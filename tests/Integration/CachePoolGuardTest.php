<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Settings\Options;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A cache_pool the CLI and the web server cannot share is refused when the
 * container is built, before a deploy can leave the apex serving a stale
 * bundle for good.
 */
final class CachePoolGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function unsharedPools(): iterable
    {
        yield 'cache.app on APCu' => [['app' => 'cache.adapter.apcu'], 'cache.app', 'APCu'];
        yield 'cache.app on the array adapter' => [['app' => 'cache.adapter.array'], 'cache.app', 'array'];
        yield 'cache.system' => [[], 'cache.system', 'APCu'];
        yield 'a pool on APCu' => [['pools' => ['app.presence' => ['adapter' => 'cache.adapter.apcu']]], 'app.presence', 'APCu'];
        yield 'a chain with an APCu tier' => [['pools' => ['app.presence' => ['adapters' => ['cache.adapter.apcu', 'cache.adapter.filesystem']]]], 'app.presence', 'APCu'];
        yield 'a tag-aware pool on APCu' => [['pools' => ['app.presence' => ['adapter' => 'cache.adapter.apcu', 'tags' => true]]], 'app.presence', 'APCu'];
    }

    /**
     * @param array<string, mixed> $frameworkCache
     */
    #[DataProvider('unsharedPools')]
    public function testAPoolPrivateToOneProcessIsRefused(array $frameworkCache, string $pool, string $kind): void
    {
        $kernel = new TestKernel(frameworkCache: $frameworkCache, cachePool: $pool);

        try {
            $kernel->boot();
            self::fail('the container must not build with '.$pool);
        } catch (\Throwable $error) {
            $message = $error->getMessage();
            self::assertStringContainsString('"'.$pool.'"', $message);
            self::assertStringContainsString($kind, $message);
            self::assertStringContainsString('globetrotters_ai_presence.cache', $message, 'names the fix');
            self::assertStringContainsString('#cache-pool', $message, 'links the README section');
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string|null}>
     */
    public static function sharedPools(): iterable
    {
        yield 'the bundle default, with cache.app on APCu' => [['app' => 'cache.adapter.apcu'], null];
        yield 'an explicit filesystem cache.app' => [[], 'cache.app'];
        yield 'an explicit filesystem pool' => [['pools' => ['app.presence' => ['adapter' => 'cache.adapter.filesystem']]], 'app.presence'];
    }

    /**
     * @param array<string, mixed> $frameworkCache
     */
    #[DataProvider('sharedPools')]
    public function testAPoolEveryProcessReadsBuilds(array $frameworkCache, ?string $pool): void
    {
        $kernel = new TestKernel(frameworkCache: $frameworkCache, cachePool: $pool);
        try {
            $kernel->boot();
            self::assertInstanceOf(Options::class, $kernel->getContainer()->get('test.service_container')->get(Options::class));
        } finally {
            $kernel->shutdown();
        }
    }
}
