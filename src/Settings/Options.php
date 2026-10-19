<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Settings;

use Globetrotters\AiPresenceBundle\Cache\LegacyPool;
use Globetrotters\AiPresenceBundle\Serving\IndexNowKey;
use Globetrotters\AiPresenceBundle\Sync\Provenance;
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
        // This environment's IndexNow key, learned from the synced version
        // marker so the apex can serve it at ``/<key>.txt``. State rather than
        // a configuration value: it is issued per environment by the presence
        // stack, rotates without the integrator's involvement, and a pasted
        // copy in config would rot silently.
        'indexnow_key' => '',
        'last_refresh' => 0,
        // When the served content last actually *changed*, as opposed to when
        // it was last confirmed unchanged. The generated sitemap's <lastmod>
        // reads this: refreshes run daily whether or not anything moved, so
        // stamping URLs with `last_refresh` would claim a freshness the content
        // does not have.
        'content_changed_at' => 0,
        'last_error' => '',
        // Which process made the last successful pull, and of which version:
        // {version, at, hostname, project_dir, user}. Compared by gt:status
        // with what the web lane reports serving (Analytics\ServedVersion), so
        // a pool the two do not share shows up as a disagreement.
        'pulled_by' => [],
    ];

    /** @var array<string, mixed>|null */
    private ?array $state = null;

    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly string $websiteUrl,
        private readonly string $refreshInterval,
        private readonly string $homepagePath,
        private readonly ?LegacyPool $legacy = null,
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
            $this->state = array_merge(self::STATE_DEFAULTS, $this->stored());
        }

        return $this->state;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function updateState(array $values): void
    {
        // Merge over the freshly-read pool item rather than the process-memoized
        // snapshot: a concurrent writer (e.g. a CLI refresh while a web request
        // holds an older state()) would otherwise be silently clobbered by our
        // stale in-memory copy. We still only overwrite the keys in $values.
        $state = array_merge(self::STATE_DEFAULTS, $this->stored(), $values);
        $item = $this->pool->getItem(self::STATE_ITEM);
        $item->set($state);
        $this->pool->save($item);
        $this->state = $state;
    }

    /**
     * Reset everything learned from the source to its defaults, for when the
     * cached bundle belongs to a website_url this install no longer points at.
     *
     * Written over the stored item rather than deleting it, so a key this class
     * does not define survives.
     */
    public function resetState(): void
    {
        $this->updateState(self::STATE_DEFAULTS);
    }

    /**
     * This environment's IndexNow key, or '' when there is none to serve.
     *
     * Sanitized on read as well as on write: the state item is a cache entry an
     * operator can edit, and the value it holds decides both which path the
     * router answers and what that response's body is.
     */
    public function indexNowKey(): string
    {
        return IndexNowKey::sanitize($this->state()['indexnow_key']);
    }

    /**
     * The last successful pull's stamp, or null before the first one.
     *
     * @return array{version: string, at: int, hostname: string, project_dir: string, user: string, env: string}|null
     */
    public function pulledBy(): ?array
    {
        $stamp = $this->state()['pulled_by'];
        if (!\is_array($stamp) || !\is_string($stamp['version'] ?? null) || !\is_int($stamp['at'] ?? null)) {
            return null;
        }

        return [
            'version' => $stamp['version'],
            'at' => $stamp['at'],
        ] + Provenance::fromRecord($stamp);
    }

    public function reset(): void
    {
        $this->state = null;
    }

    /**
     * The stored state; while this pool has none, the legacy pool's (the
     * 0.9.3 default), so a bundle served from there keeps its IndexNow key,
     * change date and version until the first refresh writes here.
     *
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        $item = $this->pool->getItem(self::STATE_ITEM);
        $stored = $item->isHit() ? $item->get() : $this->legacy?->value(self::STATE_ITEM);

        return \is_array($stored) ? $stored : [];
    }
}
