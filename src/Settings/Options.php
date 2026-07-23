<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Settings;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Bundle configuration values plus the small runtime state (installed/latest
 * version, last refresh, last error), stored as a cache-pool item so CLI
 * refreshes and web requests share it.
 */
final class Options implements ResetInterface
{
    public const STATE_ITEM = 'globetrotters_ai_presence.state';

    private const STATE_DEFAULTS = [
        'installed_version' => '',
        'latest_version' => '',
        'content_hash' => '',
        'last_refresh' => 0,
        'last_error' => '',
    ];

    /** @var array<string, mixed>|null */
    private ?array $state = null;

    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly string $websiteUrl,
        private readonly string $refreshInterval,
        private readonly string $homepagePath,
    ) {
    }

    public function baseUrl(): string
    {
        return self::normalizeUrl($this->websiteUrl);
    }

    public static function normalizeUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    public function refreshInterval(): string
    {
        return 'weekly' === $this->refreshInterval ? 'weekly' : 'daily';
    }

    public function refreshIntervalSeconds(): int
    {
        return 'weekly' === $this->refreshInterval() ? 604800 : 86400;
    }

    public function homepagePath(): string
    {
        return $this->homepagePath;
    }

    public function isConnected(): bool
    {
        return '' !== $this->baseUrl();
    }

    /**
     * Marker slug derived from the first dot-label of the configured host,
     * e.g. https://nantes.globetrotters.ai → "nantes".
     */
    public function slug(): string
    {
        $host = (string) parse_url($this->baseUrl(), \PHP_URL_HOST);
        if ('' === $host) {
            return '';
        }
        $label = explode('.', $host)[0];

        return strtolower($label);
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        if (null === $this->state) {
            $item = $this->pool->getItem(self::STATE_ITEM);
            $stored = $item->isHit() ? $item->get() : [];
            $this->state = array_merge(self::STATE_DEFAULTS, \is_array($stored) ? $stored : []);
        }

        return $this->state;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function updateState(array $values): void
    {
        $state = array_merge($this->state(), $values);
        $item = $this->pool->getItem(self::STATE_ITEM);
        $item->set($state);
        $this->pool->save($item);
        $this->state = $state;
    }

    public function reset(): void
    {
        $this->state = null;
    }
}
