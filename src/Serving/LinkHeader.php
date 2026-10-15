<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds the homepage ``Link`` header (RFC 8288): the IANA-registered
 * ``api-catalog`` (RFC 9727) and ``service-doc`` relations validators look for,
 * then the HTTP twin of the ``<link rel="mcp|agent-card|ai-catalog">`` tags
 * {@see Breadcrumb} injects:
 *
 *     Link: </.well-known/api-catalog>; rel="api-catalog", </llms.txt>; rel="service-doc", </.well-known/mcp.json>; rel="mcp", </.well-known/agent-card.json>; rel="agent-card", </.well-known/ai-catalog.json>; rel="ai-catalog"
 *
 * readiness' ``check_link_headers`` reads it off the homepage response, so it
 * goes on a ``200`` HTML response for the configured homepage only — the same
 * gate as {@see HeadInjector}. It names only the targets {@see Router} would
 * answer at these root-relative paths: a cached artefact, or the
 * {@see ApiCatalog} once it has something to list.
 *
 * Added as a header line of its own, never by replacing: a ``Link`` header the
 * application sends (WebLink's preloads, say) stays, and HTTP reads repeated
 * ``Link`` lines as one list. Priority -10, after WebLink's listener (0).
 */
final class LinkHeader implements EventSubscriberInterface
{
    /**
     * Apex path → relation, in served order: registered relations first (what
     * validators read), ours after. Mirrors gt-backend
     * ``deploy_config_renderer.APEX_HOMEPAGE_LINK_RELS`` and gt-wordpress-plugin.
     *
     * @var array<string, string>
     */
    public const RELS = [
        ApiCatalog::PATH => 'api-catalog',
        'llms.txt' => 'service-doc',
        '.well-known/mcp.json' => 'mcp',
        '.well-known/agent-card.json' => 'agent-card',
        ContentTypes::AI_CATALOG => 'ai-catalog',
    ];

    public function __construct(
        private readonly ArtefactCache $cache,
        private readonly Options $options,
        private readonly ApiCatalog $apiCatalog,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -10]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        if ($event->getRequest()->getPathInfo() !== $this->options->homepagePath()) {
            return;
        }

        $response = $event->getResponse();
        if (200 !== $response->getStatusCode()) {
            return;
        }
        // During kernel.response the Content-Type is often still unset — the
        // text/html default is only applied later by Response::prepare().
        $contentType = $response->headers->get('Content-Type');
        if (null !== $contentType && !str_starts_with($contentType, 'text/html')) {
            return;
        }

        $value = $this->value();
        if ('' === $value || \in_array($value, $response->headers->all('link'), true)) {
            return;
        }

        $response->headers->set('Link', $value, false);
    }

    /**
     * The header value for what this install serves, '' when it serves none of
     * it.
     */
    public function value(): string
    {
        $parts = [];
        foreach (self::RELS as $path => $rel) {
            $served = ApiCatalog::PATH === $path ? $this->apiCatalog->isServed() : null !== $this->cache->get($path);
            if ($served) {
                $parts[] = \sprintf('</%s>; rel="%s"', $path, $rel);
            }
        }

        return implode(', ', $parts);
    }
}
