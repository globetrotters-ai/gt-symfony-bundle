<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Analytics\PageViewOptions;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Places the page-view script before ``</body>`` on every HTML page — only
 * when ``reporting.page_views.auto_inject`` is set on top of ``enabled``.
 *
 * A second opt-in on purpose: the bundle's automatic injections are otherwise
 * limited to ``<head>`` markup that changes nothing a visitor runs. The
 * default is ``{{ gt_ai_presence_beacon() }}``, placed by the integrator in
 * their own layout where they can see it.
 *
 * The same gates as {@see HeadInjector} and {@see BreadcrumbInjector} (main
 * request, HTML), widened to any 2xx, plus: never an XHR response, which is a
 * fragment or data rather than a page view. Idempotent against a script the
 * integrator already placed with Twig.
 */
final class PageViewBeaconInjector implements EventSubscriberInterface
{
    public function __construct(
        private readonly PageViewBeacon $beacon,
        private readonly PageViewOptions $options,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Beside the other body rewriters, above ConditionalGetSubscriber, so
        // the entity-tag it recomputes describes the injected bytes.
        return [KernelEvents::RESPONSE => ['onKernelResponse', -10]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$this->options->autoInject() || !$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if ($request->isXmlHttpRequest()) {
            return;
        }

        $response = $event->getResponse();
        if (!$response->isSuccessful()) {
            return;
        }
        // As in HeadInjector: during kernel.response the Content-Type is often
        // still unset, because Response::prepare() applies the text/html
        // default later.
        $contentType = $response->headers->get('Content-Type');
        if (null !== $contentType && !str_starts_with(strtolower($contentType), 'text/html')) {
            return;
        }
        $content = $response->getContent();
        if (false === $content || '' === $content) {
            return;
        }

        $script = $this->beacon->render();
        if ('' === $script || str_contains($content, PageViewBeacon::PATH)) {
            return;
        }

        // The last </body>: an earlier one can sit inside an inline script's
        // string literal.
        $position = strripos($content, '</body>');
        if (false === $position) {
            return;
        }

        $response->setContent(substr_replace($content, $script, $position, 0));
        BodyMetadata::invalidate($response, $request);
    }
}
