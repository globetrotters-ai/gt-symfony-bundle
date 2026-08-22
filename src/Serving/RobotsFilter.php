<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Advertises the AI-crawler allow-list plus the GT-hosted sitemap on
 * /robots.txt: decorates the response when the app serves one, and serves a
 * generated robots.txt when the app would 404. Only advertises once a bundle
 * is actually cached.
 *
 * Limitation (documented): a physical public/robots.txt is served by the web
 * server and never reaches the kernel, so it can't be decorated here.
 */
final class RobotsFilter implements EventSubscriberInterface
{
    public const MARKER = '# Globetrotters AI Presence';

    private const AI_BOTS = [
        'GPTBot',
        'ChatGPT-User',
        'OAI-SearchBot',
        'ClaudeBot',
        'Claude-User',
        'Anthropic-AI',
        'PerplexityBot',
        'Google-Extended',
        'Applebot-Extended',
        'CCBot',
        'meta-externalagent',
    ];

    public function __construct(
        private readonly Options $options,
        private readonly ArtefactCache $cache,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -20],
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    /**
     * Decorate an app-served robots.txt, or generate one when the app returns
     * an explicit 404 Response (a catch-all controller that returns rather than
     * throws — the thrown case is handled in onKernelException instead).
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if ('/robots.txt' !== $request->getPathInfo()) {
            return;
        }
        if (!\in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return;
        }
        if (!$this->shouldAdvertise()) {
            return;
        }

        $response = $event->getResponse();
        $status = $response->getStatusCode();

        if (200 === $status) {
            $contentType = $response->headers->get('Content-Type');
            if (null !== $contentType && !str_starts_with($contentType, 'text/plain')) {
                return;
            }
            $content = $response->getContent();
            if (false === $content || str_contains($content, self::MARKER)) {
                return;
            }

            $response->setContent(rtrim($content, "\n")."\n\n".self::buildBlock($this->options->baseUrl()));
            BodyMetadata::invalidate($response);

            return;
        }

        // The app served a plain 404 Response (never threw), so onKernelException
        // never fired — generate the robots.txt in its place.
        if (404 === $status) {
            $response->setStatusCode(200);
            $response->setContent(self::buildBlock($this->options->baseUrl()));
            $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
            BodyMetadata::invalidate($response);
        }
    }

    /**
     * Serve a generated robots.txt when the app has none.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if ('/robots.txt' !== $request->getPathInfo()) {
            return;
        }
        if (!\in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return;
        }
        if (!$event->getThrowable() instanceof NotFoundHttpException) {
            return;
        }
        if (!$this->shouldAdvertise()) {
            return;
        }

        // Without this the kernel would force the response status back to the
        // exception's 404 (see HttpKernel::handleThrowable).
        $event->allowCustomResponseCode();
        $event->setResponse(new Response(self::buildBlock($this->options->baseUrl()), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]));
    }

    public static function buildBlock(string $baseUrl): string
    {
        $lines = [self::MARKER];
        foreach (self::AI_BOTS as $bot) {
            $lines[] = 'User-agent: '.$bot;
            $lines[] = 'Allow: /';
        }
        if ('' !== $baseUrl) {
            $lines[] = '';
            $lines[] = 'Sitemap: '.$baseUrl.'/sitemap.xml';
        }

        return implode("\n", $lines)."\n";
    }

    private function shouldAdvertise(): bool
    {
        return $this->options->isConnected() && $this->cache->hasAny();
    }
}
