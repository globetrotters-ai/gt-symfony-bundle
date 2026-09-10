<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Cache;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ArtefactCacheTest extends TestCase
{
    private const SOURCE = 'https://nantes.globetrotters.ai';

    public function testEmptyCache(): void
    {
        $cache = new ArtefactCache(new ArrayAdapter(), self::SOURCE);

        self::assertFalse($cache->hasAny());
        self::assertNull($cache->get('llms.txt'));
        self::assertSame('', $cache->version());
        self::assertSame([], $cache->files());
    }

    public function testStoreAndRead(): void
    {
        $pool = new ArrayAdapter();
        $cache = new ArtefactCache($pool, self::SOURCE);
        $cache->store(['llms.txt' => 'body', 'ai.json' => ''], 'v1', 1000);

        self::assertTrue($cache->hasAny());
        self::assertSame('body', $cache->get('llms.txt'));
        self::assertSame('', $cache->get('ai.json'));
        self::assertNull($cache->get('schema.json'));
        self::assertSame('v1', $cache->version());

        // A fresh instance on the same pool reads the persisted bundle.
        $fresh = new ArtefactCache($pool, self::SOURCE);
        self::assertSame('body', $fresh->get('llms.txt'));
    }

    public function testStoresBodiesSeparatelyBehindAManifest(): void
    {
        $pool = new ArrayAdapter();
        $cache = new ArtefactCache($pool, self::SOURCE);

        self::assertTrue($cache->store(['llms.txt' => 'small', 'schema.json' => 'large'], 'v1', 1000));

        $item = $pool->getItem(ArtefactCache::ITEM);
        self::assertTrue($item->isHit());
        $manifest = $item->get();
        self::assertIsArray($manifest);
        self::assertSame(2, $manifest['format']);
        self::assertArrayNotHasKey('files', $manifest, 'the manifest must not carry every response body');
        self::assertSame(['llms.txt', 'schema.json'], array_keys($manifest['file_items']));
        self::assertNotSame($manifest['file_items']['llms.txt'], $manifest['file_items']['schema.json']);

        // Losing an unrelated body does not force a request for llms.txt to
        // deserialize or retrieve it.
        $pool->deleteItem($manifest['file_items']['schema.json']);
        $fresh = new ArtefactCache($pool, self::SOURCE);
        self::assertSame('small', $fresh->get('llms.txt'));
        self::assertNull($fresh->get('schema.json'));
    }

    public function testReadsLegacySingleItemBundleUntilNextRefresh(): void
    {
        $pool = new ArrayAdapter();
        $item = $pool->getItem(ArtefactCache::ITEM);
        $item->set([
            'files' => ['llms.txt' => 'legacy body'],
            'version' => 'legacy-v1',
            'stored_at' => 1000,
        ]);
        $pool->save($item);

        $cache = new ArtefactCache($pool, self::SOURCE);

        self::assertSame('legacy body', $cache->get('llms.txt'));
        self::assertSame('legacy-v1', $cache->version());
        self::assertTrue($cache->hasAny());
    }

    public function testFailedManifestWriteKeepsPublishedBundleAndMemo(): void
    {
        $pool = new class extends ArrayAdapter {
            public bool $failManifest = false;

            public function save(CacheItemInterface $item): bool
            {
                if ($this->failManifest && ArtefactCache::ITEM === $item->getKey()) {
                    return false;
                }

                return parent::save($item);
            }
        };
        $cache = new ArtefactCache($pool, self::SOURCE);
        self::assertTrue($cache->store(['llms.txt' => 'old'], 'v1', 1000));

        $pool->failManifest = true;
        self::assertFalse($cache->store(['llms.txt' => 'new'], 'v2', 2000));

        self::assertSame('old', $cache->get('llms.txt'), 'the process memo must retain the published generation');
        $fresh = new ArtefactCache($pool, self::SOURCE);
        self::assertSame('old', $fresh->get('llms.txt'), 'other processes must retain the published generation');
        self::assertSame('v1', $fresh->version());
    }

    public function testAFailedPublicationLeavesNoOrphanedBodies(): void
    {
        $pool = new class extends ArrayAdapter {
            public bool $failManifest = false;

            public function save(CacheItemInterface $item): bool
            {
                if ($this->failManifest && ArtefactCache::ITEM === $item->getKey()) {
                    return false;
                }

                return parent::save($item);
            }
        };
        $cache = new ArtefactCache($pool, self::SOURCE);
        self::assertTrue($cache->store(['llms.txt' => 'old', 'schema.json' => 'kept'], 'v1', 1000));

        $manifest = $pool->getItem(ArtefactCache::ITEM)->get();
        self::assertIsArray($manifest);
        $published = $manifest['file_items'];

        $pool->failManifest = true;
        self::assertFalse($cache->store(['llms.txt' => 'new', 'schema.json' => 'kept'], 'v2', 2000));

        // The body written for the abandoned generation is reachable from no
        // manifest at all, so it must not linger. The unchanged body is
        // addressed by the same key in both generations and must survive.
        foreach ($published as $key) {
            self::assertTrue($pool->getItem($key)->isHit(), 'the published generation must survive a failed publication');
        }
        self::assertCount(
            2,
            array_filter(
                array_keys($pool->getValues()),
                static fn (string $key): bool => str_starts_with($key, 'globetrotters_ai_presence.file.'),
            ),
            'the abandoned generation must not leave an orphaned body behind',
        );
    }

    public function testCacheKeysStayInsideThePsr6GuaranteedLength(): void
    {
        $pool = new ArrayAdapter();
        (new ArtefactCache($pool, self::SOURCE))->store(['llms.txt' => str_repeat('x', 4096)], 'v1', 1000);

        $manifest = $pool->getItem(ArtefactCache::ITEM)->get();
        self::assertIsArray($manifest);
        foreach ([ArtefactCache::ITEM, ...array_values($manifest['file_items'])] as $key) {
            self::assertLessThanOrEqual(64, \strlen((string) $key), $key.' exceeds the key length PSR-6 guarantees');
        }
    }

    public function testClear(): void
    {
        $cache = new ArtefactCache(new ArrayAdapter(), self::SOURCE);
        $cache->store(['llms.txt' => 'body'], 'v1', 1000);
        $cache->clear();

        self::assertFalse($cache->hasAny());
    }

    public function testClearStopsServingEvenWhenThePoolThrows(): void
    {
        $pool = new class extends ArrayAdapter {
            /**
             * @param array<string> $keys
             */
            public function deleteItems(array $keys): bool
            {
                throw new \RuntimeException('backend unavailable');
            }
        };
        $cache = new ArtefactCache($pool, self::SOURCE);
        $cache->store(['llms.txt' => 'body'], 'v1', 1000);

        $cache->clear();

        // Deleting the bodies blew up, but the caller asked for the bundle to
        // go: the manifest is gone and the memo was dropped regardless.
        self::assertFalse($cache->hasAny());
        self::assertNull($cache->get('llms.txt'));
    }

    public function testResetDropsMemo(): void
    {
        $pool = new ArrayAdapter();
        $reader = new ArtefactCache($pool, self::SOURCE);
        $writer = new ArtefactCache($pool, self::SOURCE);

        self::assertFalse($reader->hasAny());
        $writer->store(['llms.txt' => 'body'], 'v1', 1000);

        // Memoised view until reset (kernel.reset in workers).
        self::assertFalse($reader->hasAny());
        $reader->reset();
        self::assertTrue($reader->hasAny());
    }

    /**
     * The cache pool outlives a deploy; the configuration does not. A bundle
     * pulled for one website_url must not be served by an install now pointed
     * at another.
     */
    public function testABundleIsServedOnlyForTheSourceItWasPulledFrom(): void
    {
        $pool = new ArrayAdapter();
        (new ArtefactCache($pool, self::SOURCE))->store(['llms.txt' => 'body'], 'v1', 1000);

        $repointed = new ArtefactCache($pool, 'https://lyon.globetrotters.ai');

        self::assertFalse($repointed->hasAny());
        self::assertNull($repointed->get('llms.txt'));
        self::assertSame([], $repointed->files());
        self::assertSame('', $repointed->version());
        self::assertTrue($repointed->holdsForeignBundle());

        // Compared normalized, as Options reads the URL: a trailing slash or
        // surrounding whitespace is not a new source.
        $same = new ArtefactCache($pool, self::SOURCE.'/ ');
        self::assertSame('body', $same->get('llms.txt'));
        self::assertFalse($same->holdsForeignBundle());
    }

    public function testNothingIsServedOnceNoSourceIsConfigured(): void
    {
        $pool = new ArrayAdapter();
        (new ArtefactCache($pool, self::SOURCE))->store(['llms.txt' => 'body'], 'v1', 1000);

        $cleared = new ArtefactCache($pool, '');

        self::assertFalse($cleared->hasAny());
        self::assertNull($cleared->get('llms.txt'));
        self::assertTrue($cleared->holdsForeignBundle());
    }

    /**
     * A manifest written by 0.4.0 or earlier records no source. Refusing it
     * would take every install dark on upgrade until its next refresh.
     */
    public function testABundleFromBeforeSourcesWereRecordedStaysServableWhileConnected(): void
    {
        $pool = new ArrayAdapter();
        (new ArtefactCache($pool, self::SOURCE))->store(['llms.txt' => 'body'], 'v1', 1000);
        $item = $pool->getItem(ArtefactCache::ITEM);
        $manifest = $item->get();
        self::assertIsArray($manifest);
        unset($manifest['source']);
        $pool->save($item->set($manifest));

        $upgraded = new ArtefactCache($pool, 'https://lyon.globetrotters.ai');
        self::assertSame('body', $upgraded->get('llms.txt'));
        self::assertFalse($upgraded->holdsForeignBundle());

        $cleared = new ArtefactCache($pool, '');
        self::assertNull($cleared->get('llms.txt'));
        self::assertTrue($cleared->holdsForeignBundle());
    }

    public function testClearRemovesAForeignBundleItCannotServe(): void
    {
        $pool = new ArrayAdapter();
        (new ArtefactCache($pool, self::SOURCE))->store(['llms.txt' => 'body'], 'v1', 1000);

        (new ArtefactCache($pool, 'https://lyon.globetrotters.ai'))->clear();

        self::assertFalse($pool->getItem(ArtefactCache::ITEM)->isHit());
        self::assertSame([], array_values(array_filter(
            array_keys($pool->getValues()),
            static fn (string $key): bool => str_starts_with($key, 'globetrotters_ai_presence.file.'),
        )));
    }
}
