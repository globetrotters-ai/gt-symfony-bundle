<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

/**
 * The page-view counter's two opt-ins, layered on the reporting lane's
 * configuration.
 *
 * Off by default, and separate from agent-traffic reporting: counting human
 * page views puts a script on the integrator's pages and a counter endpoint on
 * their site, which is a decision for them to make, not a side effect of
 * pasting an ingest token. And it only runs while reporting itself is
 * configured — counts that could never be sent are not worth writing down.
 */
final class PageViewOptions
{
    public function __construct(
        private readonly AnalyticsOptions $analytics,
        private readonly bool $enabled,
        private readonly bool $autoInject,
    ) {
    }

    /**
     * Whether ``reporting.page_views.enabled`` is set, configured or not.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Whether the counter runs: opted in, and reporting configured.
     */
    public function isActive(): bool
    {
        return $this->enabled && $this->analytics->isConfigured();
    }

    /**
     * Whether the bundle places the script itself, before ``</body>``.
     */
    public function autoInject(): bool
    {
        return $this->autoInject && $this->isActive();
    }
}
