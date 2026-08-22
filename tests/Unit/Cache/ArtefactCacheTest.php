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

    public function testClear(): void
    {
        $cache = new ArtefactCache(new ArrayAdapter());
        $cache->store(['llms.txt' => 'body'], 'v1', 1000);
        $cache->clear();

        self::assertFalse($cache->hasAny());
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
