<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Settings\BreadcrumbOptions;

/**
 * Renders the breadcrumb halves against the current cache and configuration.
 *
 * Sits between the pure markup in {@see Breadcrumb} and the two consumers that
 * must never disagree: {@see BreadcrumbInjector}, which places the markup
 * automatically, and
 * {@see \Globetrotters\AiPresenceBundle\Twig\AiPresenceExtension}, which lets an
 * integrator place it themselves. Both go through here so "which origin" and
 * "which anchor text" are answered in exactly one place.
 */
final class BreadcrumbRenderer
{
    public function __construct(
        private readonly ArtefactCache $cache,
        private readonly BreadcrumbOptions $options,
    ) {
    }

    /**
     * Whether this install links back at all. False under
     * {@see \Globetrotters\AiPresenceBundle\Settings\Profile::FullApex}, where
     * there is no second host to point at.
     */
    public function isEnabled(): bool
    {
        return $this->options->isBreadcrumb();
    }

    /**
     * The canonical Globetrotters origin as of the last refresh, or ''.
     *
     * Read from the cache per call rather than memoized: a refresh can land
     * between two requests of a long-lived worker, and picking up a hostname
     * flip without a redeploy is the entire reason this is derived rather than
     * configured.
     */
    public function origin(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        return Breadcrumb::originFrom($this->cache->get('ai.json'));
    }

    public function headBlock(): string
    {
        return Breadcrumb::headBlock($this->origin());
    }

    /**
     * The visible anchor, using the configured text or the destination name.
     *
     * Deliberately ignores ``breadcrumb.inject_anchor``: that switch turns off
     * *automatic* placement, and an integrator who turned it off in order to
     * place the anchor themselves must still get markup back from the Twig
     * function.
     */
    public function anchor(): string
    {
        $origin = $this->origin();
        if ('' === $origin) {
            return '';
        }
        $text = $this->options->anchorText();
        if ('' === $text) {
            $text = Breadcrumb::defaultAnchorText($this->cache->get('ai.json'));
        }

        return Breadcrumb::anchor($origin, $text);
    }
}
