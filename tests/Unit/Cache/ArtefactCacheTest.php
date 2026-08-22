<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Cache;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ArtefactCacheTest extends TestCase
{
    public function testEmptyCache(): void
    {
        $cache = new ArtefactCache(new ArrayAdapter());

        self::assertFalse($cache->hasAny());
        self::assertNull($cache->get('llms.txt'));
        self::assertSame('', $cache->version());
        self::assertSame([], $cache->files());
    }

    public function testStoreAndRead(): void
    {
        $pool = new ArrayAdapter();
        $cache = new ArtefactCache($pool);
        $cache->store(['llms.txt' => 'body', 'ai.json' => ''], 'v1', 1000);

        self::assertTrue($cache->hasAny());
        self::assertSame('body', $cache->get('llms.txt'));
        self::assertSame('', $cache->get('ai.json'));
        self::assertNull($cache->get('schema.json'));
        self::assertSame('v1', $cache->version());

        // A fresh instance on the same pool reads the persisted bundle.
        $fresh = new ArtefactCache($pool);
        self::assertSame('body', $fresh->get('llms.txt'));
    }

    public function testStoresBodiesSeparatelyBehindAManifest(): void
    {
        $pool = new ArrayAdapter();
        $cache = new ArtefactCache($pool);

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
        $fresh = new ArtefactCache($pool);
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

        $cache = new ArtefactCache($pool);

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
        $cache = new ArtefactCache($pool);
        self::assertTrue($cache->store(['llms.txt' => 'old'], 'v1', 1000));

        $pool->failManifest = true;
        self::assertFalse($cache->store(['llms.txt' => 'new'], 'v2', 2000));

        self::assertSame('old', $cache->get('llms.txt'), 'the process memo must retain the published generation');
        $fresh = new ArtefactCache($pool);
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
        $cache = new ArtefactCache($pool);
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
        (new ArtefactCache($pool))->store(['llms.txt' => str_repeat('x', 4096)], 'v1', 1000);

        $manifest = $pool->getItem(ArtefactCache::ITEM)->get();
        self::assertIsArray($manifest);
        foreach ([ArtefactCache::ITEM, ...array_values($manifest['file_items'])] as $key) {
            self::assertLessThanOrEqual(64, \strlen((string) $key), $key.' exceeds the key length PSR-6 guarantees');
        }
    }

    public function testClear(): void
    {
        $cache = new ArtefactCache(new ArrayAdapter());
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
        $cache = new ArtefactCache($pool);
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
        $reader = new ArtefactCache($pool);
        $writer = new ArtefactCache($pool);

        self::assertFalse($reader->hasAny());
        $writer->store(['llms.txt' => 'body'], 'v1', 1000);

        // Memoised view until reset (kernel.reset in workers).
        self::assertFalse($reader->hasAny());
        $reader->reset();
        self::assertTrue($reader->hasAny());
    }
}
