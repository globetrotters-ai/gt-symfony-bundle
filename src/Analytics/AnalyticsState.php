<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Runtime state for the reporting lane: what the last flush did, which
 * scheduling lane it came from, and whether client-IP resolution looks
 * trustworthy.
 *
 * Held in the shared cache pool alongside {@see \Globetrotters\AiPresenceBundle\Settings\Options}'
 * sync state, and written with the same merge-over-a-fresh-read pattern, so a
 * CLI flush and a web request don't clobber one another. Unlike the drop
 * counter this is diagnostic rather than accounting: a lost update costs a
 * slightly stale status line, never a miscounted event — which is why the drop
 * counter lives on the filesystem under a lock instead.
 */
final class AnalyticsState implements ResetInterface
{
    public const STATE_ITEM = 'globetrotters_ai_presence.reporting_state';

    public const LANE_COMMAND = 'command';
    public const LANE_SCHEDULER = 'scheduler';
    public const LANE_TERMINATE = 'kernel.terminate';

    private const STATE_DEFAULTS = [
        'last_flush_attempt' => 0,
        'last_flush_ok' => 0,
        'last_flush_error' => '',
        'last_flush_lane' => '',
        'flush_count' => 0,
        'events_sent' => 0,
        // Whether a captured request has ever been observed, and whether the
        // client IP on those requests could be resolved past a proxy.
        'ip_observed' => false,
        'ip_trustworthy' => true,
    ];

    /** @var array<string, mixed>|null */
    private ?array $state = null;

    public function __construct(private readonly CacheItemPoolInterface $pool)
    {
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
    public function update(array $values): void
    {
        $item = $this->pool->getItem(self::STATE_ITEM);
        $stored = $item->isHit() ? $item->get() : [];
        $state = array_merge(
            self::STATE_DEFAULTS,
            \is_array($stored) ? $stored : [],
            $values,
        );
        $item->set($state);
        $this->pool->save($item);
        $this->state = $state;
    }

    /**
     * Record what client-IP resolution looked like on a captured request.
     *
     * Only writes when the observation changed, so the common case costs one
     * memoized read rather than a cache write per served artefact request.
     */
    public function observeIpTrust(bool $trustworthy): void
    {
        $state = $this->state();
        if (true === $state['ip_observed'] && $trustworthy === $state['ip_trustworthy']) {
            return;
        }

        $this->update(['ip_observed' => true, 'ip_trustworthy' => $trustworthy]);
    }

    public function reset(): void
    {
        $this->state = null;
    }
}
