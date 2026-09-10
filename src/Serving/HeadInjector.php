<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Injects the cached schema.json as a server-rendered JSON-LD script into the
 * homepage HTML, so crawlers see it in the raw markup without executing JS.
 * v1 injects on the configured homepage path only.
 */
final class HeadInjector implements EventSubscriberInterface
{
    public function __construct(
        private readonly ArtefactCache $cache,
        private readonly Options $options,
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
        $content = $response->getContent();
        if (false === $content) {
            return;
        }

        $json = $this->cache->get('schema.json');
        if (null === $json) {
            return;
        }
        $markup = self::render($json);
        // Compare against the tag without its trailing newline so the guard
        // still detects a manually-rendered {{ gt_ai_presence_head() }} tag
        // whose newline a Twig trim or HTML minifier may have stripped —
        // otherwise the byte-exact match misses it and we double-inject.
        if ('' === $markup || str_contains($content, rtrim($markup, "\n"))) {
            return;
        }

        $position = stripos($content, '</head>');
        if (false === $position) {
            return;
        }

        $response->setContent(substr_replace($content, $markup, $position, 0));
        // The injected JSON-LD changed the body, so anything describing the
        // original representation must go with it — and the entity-tag is
        // recomputed and revalidated against this request rather than dropped.
        BodyMetadata::invalidate($response, $event->getRequest());
    }

    /**
     * Breakout-safe JSON-LD script builder: re-encodes through a JSON
     * round-trip (dropping invalid JSON) with every "<" and ">" written as a
     * JSON unicode escape (JSON_HEX_TAG), which any JSON-LD consumer decodes
     * straight back.
     *
     * Escaping only "</" is not enough. A value such as "<!--<script>" moves
     * the HTML tokenizer into the double-escaped script state, where the real
     * closing tag no longer ends the element and the rest of the homepage is
     * swallowed into the script. Script data only changes state on a "<", so a
     * body containing none cannot leave it, whatever the value says.
     */
    public static function render(string $json): string
    {
        if ('' === trim($json)) {
            return '';
        }
        $decoded = json_decode($json);
        if (null === $decoded) {
            return '';
        }
        $encoded = json_encode($decoded, \JSON_HEX_TAG);
        if (!\is_string($encoded)) {
            return '';
        }

        return '<script type="application/ld+json">'.$encoded.'</script>'."\n";
    }
}
