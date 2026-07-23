<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The pulled artefact bundle, stored as a single cache item with no
 * expiration. Stale-serve is structural: only a *successful* full pull calls
 * store(), so an unreachable Globetrotters leaves the previous bundle serving.
 */
final class ArtefactCache implements ResetInterface
{
    public const ITEM = 'globetrotters_ai_presence.artefacts';

    /** @var array<string, mixed>|null */
    private ?array $bundle = null;

    public function __construct(private readonly CacheItemPoolInterface $pool)
    {
    }

    public function get(string $path): ?string
    {
        $files = $this->files();
        if (!\array_key_exists($path, $files)) {
            return null;
        }

        return (string) $files[$path];
    }

    /**
     * @return array<string, string>
     */
    public function files(): array
    {
        $files = $this->bundle()['files'] ?? null;

        return \is_array($files) ? $files : [];
    }

    public function version(): string
    {
        return (string) ($this->bundle()['version'] ?? '');
    }

    public function hasAny(): bool
    {
        return [] !== $this->files();
    }

    /**
     * @param array<string, string> $files
     */
    public function store(array $files, string $version, int $storedAt): void
    {
        $bundle = [
            'files' => $files,
            'version' => $version,
            'stored_at' => $storedAt,
        ];
        $item = $this->pool->getItem(self::ITEM);
        $item->set($bundle);
        $this->pool->save($item);
        $this->bundle = $bundle;
    }

    public function clear(): void
    {
        $this->pool->deleteItem(self::ITEM);
        $this->bundle = null;
    }

    public function reset(): void
    {
        $this->bundle = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function bundle(): array
    {
        if (null === $this->bundle) {
            $item = $this->pool->getItem(self::ITEM);
            $stored = $item->isHit() ? $item->get() : [];
            $this->bundle = \is_array($stored) ? $stored : [];
        }

        return $this->bundle;
    }
}
