<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The pulled artefact bundle, published through a small atomic manifest.
 *
 * Each body lives in its own content-addressed cache item. A refresh writes all
 * bodies first and only then switches the manifest, so readers see either the
 * complete old generation or the complete new one. Keeping bodies separate
 * avoids deserializing the whole (potentially multi-megabyte) bundle for every
 * artefact request and avoids exceeding a cache backend's per-item limit merely
 * because otherwise-independent bodies were combined.
 *
 * Legacy v1 single-item bundles remain readable and are migrated on the next
 * successful refresh.
 */
final class ArtefactCache implements ResetInterface
{
    public const ITEM = 'globetrotters_ai_presence.artefacts';

    private const FORMAT = 2;
    private const FILE_ITEM_PREFIX = 'globetrotters_ai_presence.file.';

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    /** @var array<string, string|null> */
    private array $fileMemo = [];

    public function __construct(private readonly CacheItemPoolInterface $pool)
    {
    }

    public function get(string $path): ?string
    {
        if (\array_key_exists($path, $this->fileMemo)) {
            return $this->fileMemo[$path];
        }

        $manifest = $this->manifest();
        if ($this->isCurrentFormat($manifest)) {
            $fileItems = $manifest['file_items'];
            \assert(\is_array($fileItems));
            $key = $fileItems[$path] ?? null;
            if (!\is_string($key) || '' === $key) {
                return $this->fileMemo[$path] = null;
            }

            $item = $this->pool->getItem($key);
            $body = $item->isHit() ? $item->get() : null;

            return $this->fileMemo[$path] = \is_string($body) ? $body : null;
        }

        // v1 stored the bodies directly under `files` in the manifest item.
        $files = $manifest['files'] ?? null;
        if (!\is_array($files) || !\array_key_exists($path, $files)) {
            return $this->fileMemo[$path] = null;
        }

        return $this->fileMemo[$path] = (string) $files[$path];
    }

    /**
     * @return array<string, string>
     */
    public function files(): array
    {
        $manifest = $this->manifest();
        $paths = $this->isCurrentFormat($manifest)
            ? array_keys($manifest['file_items'])
            : array_keys(\is_array($manifest['files'] ?? null) ? $manifest['files'] : []);

        $files = [];
        foreach ($paths as $path) {
            if (!\is_string($path)) {
                continue;
            }
            $body = $this->get($path);
            if (null !== $body) {
                $files[$path] = $body;
            }
        }

        return $files;
    }

    public function version(): string
    {
        return (string) ($this->manifest()['version'] ?? '');
    }

    public function hasAny(): bool
    {
        $manifest = $this->manifest();
        $paths = $this->isCurrentFormat($manifest)
            ? array_keys($manifest['file_items'])
            : array_keys(\is_array($manifest['files'] ?? null) ? $manifest['files'] : []);

        foreach ($paths as $path) {
            if (\is_string($path) && null !== $this->get($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Persist and atomically publish a complete bundle.
     *
     * Returns false without changing the published manifest or the process memo
     * when any cache write reports failure.
     *
     * @param array<string, string> $files
     */
    public function store(array $files, string $version, int $storedAt): bool
    {
        $oldManifest = $this->manifest();
        $oldCurrentItems = $this->fileItemKeys($oldManifest['file_items'] ?? null);
        $oldPreviousItems = $this->stringList($oldManifest['previous_file_items'] ?? null);

        // Every generation that stays published if this call aborts. A body
        // whose content did not change is addressed by the same key in both, so
        // rolling back must never delete one of these.
        $published = array_merge($oldCurrentItems, $oldPreviousItems);

        $fileItems = [];
        $written = [];
        foreach ($files as $path => $body) {
            $key = self::fileItemKey($path, $body);
            $item = $this->pool->getItem($key);
            $item->set($body);
            if (!$this->pool->save($item)) {
                $this->discardOrphans($written, $published);

                return false;
            }
            $written[] = $key;
            $fileItems[$path] = $key;
        }

        $manifest = [
            'format' => self::FORMAT,
            'file_items' => $fileItems,
            // Keep one previous generation so a reader that loaded the old
            // manifest just before publication can still resolve every body.
            'previous_file_items' => array_values(array_unique($oldCurrentItems)),
            'version' => $version,
            'stored_at' => $storedAt,
        ];
        $item = $this->pool->getItem(self::ITEM);
        $item->set($manifest);
        if (!$this->pool->save($item)) {
            $this->discardOrphans($written, $published);

            return false;
        }

        $this->manifest = $manifest;
        $this->fileMemo = $files;

        // Items older than the retained previous generation are no longer
        // reachable. Cleanup is best-effort and cannot undo a published bundle.
        $keep = array_flip(array_merge(array_values($fileItems), $oldCurrentItems));
        $stale = array_values(array_filter(
            $oldPreviousItems,
            static fn (string $key): bool => !isset($keep[$key]),
        ));
        if ([] !== $stale) {
            try {
                $this->pool->deleteItems($stale);
            } catch (\Throwable) {
                // Publication already succeeded; eventual cache eviction can
                // reclaim an orphan if this backend cannot delete it now.
            }
        }

        return true;
    }

    public function clear(): void
    {
        $manifest = $this->manifest();
        $keys = array_values(array_unique(array_merge(
            $this->fileItemKeys($manifest['file_items'] ?? null),
            $this->stringList($manifest['previous_file_items'] ?? null),
        )));

        // Unconditional: a pool that reports a failed manifest delete must not
        // leave the bodies behind as unreachable garbage, nor leave this
        // process serving a bundle the caller asked to remove.
        try {
            $this->pool->deleteItem(self::ITEM);
            if ([] !== $keys) {
                $this->pool->deleteItems($keys);
            }
        } catch (\Throwable) {
            // Best-effort, as in store(): a backend that cannot delete right
            // now must still not leave this process serving the bundle.
        }
        $this->reset();
    }

    public function reset(): void
    {
        $this->manifest = null;
        $this->fileMemo = [];
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        if (null === $this->manifest) {
            $item = $this->pool->getItem(self::ITEM);
            $stored = $item->isHit() ? $item->get() : [];
            $this->manifest = \is_array($stored) ? $stored : [];
        }

        return $this->manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function isCurrentFormat(array $manifest): bool
    {
        return self::FORMAT === ($manifest['format'] ?? null)
            && \is_array($manifest['file_items'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function fileItemKeys(mixed $value): array
    {
        return \is_array($value) ? $this->stringList(array_values($value)) : [];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $key): bool => \is_string($key) && '' !== $key));
    }

    /**
     * Drop bodies written for a publication that never happened.
     *
     * @param list<string> $written   keys this aborted call created
     * @param list<string> $published keys the still-published generations need
     */
    private function discardOrphans(array $written, array $published): void
    {
        $keep = array_flip($published);
        $orphans = array_values(array_filter(
            $written,
            static fn (string $key): bool => !isset($keep[$key]),
        ));
        if ([] === $orphans) {
            return;
        }

        try {
            $this->pool->deleteItems($orphans);
        } catch (\Throwable) {
            // Best-effort: the published bundle is intact either way.
        }
    }

    private static function fileItemKey(string $path, string $body): string
    {
        // Truncated to keep the whole key inside the 64 characters every PSR-6
        // pool is required to support (31-character prefix + 32 hex digits).
        // 128 bits is ample for addressing a handful of artefact bodies.
        return self::FILE_ITEM_PREFIX.substr(hash('sha256', $path."\0".$body), 0, 32);
    }
}
