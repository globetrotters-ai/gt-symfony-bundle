<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Twig;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Serving\BreadcrumbRenderer;
use Globetrotters\AiPresenceBundle\Serving\HeadInjector;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Opt-in alternative to the automatic injection: place these in a base template
 * to render the markup exactly where you want it. Each has a matching
 * idempotency guard on the subscriber side, so using one does not produce a
 * second copy.
 *
 * - ``{{ gt_ai_presence_head() }}`` — the JSON-LD tag, on any profile.
 * - ``{{ gt_ai_presence_breadcrumb_head() }}`` and
 *   ``{{ gt_ai_presence_breadcrumb_link() }}`` — the two halves of the
 *   ``subdomain_breadcrumb`` breadcrumb, empty strings on any other profile.
 *   They are separate functions because they belong in different places: the
 *   first in ``<head>``, the second in the visible footer, which is what a
 *   crawler actually follows.
 */
final class AiPresenceExtension extends AbstractExtension
{
    public function __construct(
        private readonly ArtefactCache $cache,
        private readonly BreadcrumbRenderer $breadcrumb,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('gt_ai_presence_head', $this->renderHead(...), ['is_safe' => ['html']]),
            new TwigFunction('gt_ai_presence_breadcrumb_head', $this->renderBreadcrumbHead(...), ['is_safe' => ['html']]),
            new TwigFunction('gt_ai_presence_breadcrumb_link', $this->renderBreadcrumbLink(...), ['is_safe' => ['html']]),
        ];
    }

    public function renderHead(): string
    {
        return HeadInjector::render($this->cache->get('schema.json') ?? '');
    }

    public function renderBreadcrumbHead(): string
    {
        return $this->breadcrumb->headBlock();
    }

    public function renderBreadcrumbLink(): string
    {
        return $this->breadcrumb->anchor();
    }
}
