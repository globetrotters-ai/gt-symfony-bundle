<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Cache;

use Psr\Cache\CacheItemPoolInterface;

/**
 * The pool 0.9.3 and earlier kept the bundle in by default (``cache.app``),
 * read — never written — while the bundle's own pool is still empty after an
 * upgrade, so the apex keeps serving the bundle it had until the first refresh
 * fills the new pool instead of answering 404 until the next cron tick.
 *
 * Only wired when ``cache_pool`` is left at its default: an install that chose
 * its pool keeps exactly that pool. It may be APCu (the build guard applies to
 * the configured pool only); a read-only stopgap from the web's own APCu still
 * serves what that web served before. Resolved lazily and defensively: on a
 * PHP without APCu, ``cache.app`` on APCu throws when it is built, and that
 * must cost the fallback, never the bundle.
 */
final class LegacyPool
{
    private ?CacheItemPoolInterface $pool = null;

    private bool $resolved = false;

    public function __construct(private readonly \Closure $factory)
    {
    }

    /**
     * The stored value under $key, or null on a miss or any failure.
     */
    public function value(string $key): mixed
    {
        $pool = $this->pool();
        if (null === $pool) {
            return null;
        }

        try {
            $item = $pool->getItem($key);

            return $item->isHit() ? $item->get() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function pool(): ?CacheItemPoolInterface
    {
        if (!$this->resolved) {
            $this->resolved = true;
            try {
                $pool = ($this->factory)();
                $this->pool = $pool instanceof CacheItemPoolInterface ? $pool : null;
            } catch (\Throwable) {
                $this->pool = null;
            }
        }

        return $this->pool;
    }
}
