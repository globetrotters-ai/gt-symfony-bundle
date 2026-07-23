<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Twig;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Serving\HeadInjector;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Opt-in alternative to the automatic head injection: place
 * {{ gt_ai_presence_head() }} in a base template to render the JSON-LD tag
 * explicitly. The HeadInjector's idempotency guard prevents double injection.
 */
final class AiPresenceExtension extends AbstractExtension
{
    public function __construct(private readonly ArtefactCache $cache)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('gt_ai_presence_head', $this->renderHead(...), ['is_safe' => ['html']]),
        ];
    }

    public function renderHead(): string
    {
        return HeadInjector::render($this->cache->get('schema.json') ?? '');
    }
}
