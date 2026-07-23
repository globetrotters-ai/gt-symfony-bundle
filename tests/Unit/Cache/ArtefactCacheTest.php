<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Cache;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use PHPUnit\Framework\TestCase;
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
