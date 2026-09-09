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
     * Both halves plus their guards, derived from one read of the cached
     * ai.json, or null when this install emits no breadcrumb.
     *
     * Read per call rather than memoized here: a refresh can land between two
     * requests of a long-lived worker, and picking up a hostname flip without a
     * redeploy is the entire reason the origin is derived rather than
     * configured. Within one call everything comes from the same bytes.
     */
    public function render(): ?RenderedBreadcrumb
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $aiJson = $this->cache->get('ai.json');
        $origin = Breadcrumb::originFrom($aiJson);
        if ('' === $origin) {
            return null;
        }

        $text = $this->options->anchorText();
        if ('' === $text) {
            $text = Breadcrumb::defaultAnchorText($aiJson);
        }

        return new RenderedBreadcrumb(
            $origin,
            Breadcrumb::headBlock($origin),
            Breadcrumb::anchor($origin, $text),
        );
    }

    /** The head half, for explicit placement from Twig. */
    public function headBlock(): string
    {
        $breadcrumb = $this->render();

        return null === $breadcrumb ? '' : $breadcrumb->headBlock;
    }

    /**
     * The visible anchor, for explicit placement from Twig.
     *
     * Never injected automatically — see {@see BreadcrumbInjector}. It exists
     * only for an integrator placing it inside their own layout.
     */
    public function anchor(): string
    {
        $breadcrumb = $this->render();

        return null === $breadcrumb ? '' : $breadcrumb->anchor;
    }
}
