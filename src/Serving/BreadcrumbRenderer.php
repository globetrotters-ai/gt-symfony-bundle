<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Settings\BreadcrumbOptions;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders the breadcrumb halves against the current cache and configuration.
 *
 * Sits between the pure markup in {@see Breadcrumb} and the two consumers that
 * must never disagree: {@see BreadcrumbInjector}, which places the markup
 * automatically, and
 * {@see \Globetrotters\AiPresenceBundle\Twig\AiPresenceExtension}, which lets an
 * integrator place it themselves. Both go through here so "which origin",
 * "which anchor text" and "is this the homepage" are answered in exactly one
 * place — a Twig call in a base template must produce the same block the
 * subscriber would have injected into that same page.
 */
final class BreadcrumbRenderer
{
    public function __construct(
        private readonly ArtefactCache $cache,
        private readonly BreadcrumbOptions $options,
        private readonly Options $settings,
        private readonly RequestStack $requests,
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
    public function render(?Request $request = null): ?RenderedBreadcrumb
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
            Breadcrumb::headBlock($origin, $this->isHomepage($request)),
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

    /**
     * Whether the request being rendered is the configured homepage, which is
     * the only page whose ``rel="alternate"`` claim is true.
     *
     * A caller holding the request passes it: a response subscriber has the
     * authoritative one and should not have to trust that the stack agrees.
     * Twig has no request to hand, so it falls back to the stack.
     *
     * No request at all (a console render, a warm-up) is treated as "not the
     * homepage": the site-scoped relations are correct everywhere, and asserting
     * the document-scoped one with no page to assert it about would be a guess.
     */
    private function isHomepage(?Request $request): bool
    {
        $request ??= $this->requests->getCurrentRequest();

        return null !== $request && $request->getPathInfo() === $this->settings->homepagePath();
    }
}
