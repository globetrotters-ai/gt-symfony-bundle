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
        $response->headers->remove('Content-Length');
    }

    /**
     * Breakout-safe JSON-LD script builder: re-encodes through a JSON
     * round-trip (dropping invalid JSON) and escapes every "</" so a value
     * can't close the script tag early.
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
        $encoded = json_encode($decoded);
        if (!\is_string($encoded)) {
            return '';
        }
        $safe = str_replace('</', '<\/', $encoded);

        return '<script type="application/ld+json">'.$safe.'</script>'."\n";
    }
}
