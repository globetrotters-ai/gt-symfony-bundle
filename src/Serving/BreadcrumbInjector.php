<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Settings\BreadcrumbOptions;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Links the apex back to the presence published at the Globetrotters host, for
 * the ``subdomain_breadcrumb`` install profile only.
 *
 * Two insertions, in two different places, because they do two different jobs:
 * the head block ({@see Breadcrumb::headBlock()}) carries machine-readable
 * pointers, and the footer anchor ({@see Breadcrumb::anchor()}) is the actual
 * discovery signal — a crawler follows an ``<a href>``, not a ``<link>``. Under
 * {@see \Globetrotters\AiPresenceBundle\Settings\Profile::FullApex} this
 * subscriber does nothing at all.
 *
 * Runs beside {@see HeadInjector} at the same priority and applies the same
 * gates (main request, configured homepage path, 200, HTML). Both subscribers
 * mutate the homepage body independently, and each invalidates the metadata
 * that describes it.
 *
 * Every injection is idempotent against the Twig functions
 * ``gt_ai_presence_breadcrumb_head()`` / ``gt_ai_presence_breadcrumb_link()``,
 * which exist for integrators who would rather place the markup themselves —
 * checked separately per half, so placing one by hand still gets the other.
 */
final class BreadcrumbInjector implements EventSubscriberInterface
{
    public function __construct(
        private readonly Options $options,
        private readonly BreadcrumbOptions $breadcrumb,
        private readonly BreadcrumbRenderer $renderer,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -10]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$this->renderer->isEnabled()) {
            return;
        }
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
        // As in HeadInjector: during kernel.response the Content-Type is often
        // still unset, because Response::prepare() applies the text/html
        // default later.
        $contentType = $response->headers->get('Content-Type');
        if (null !== $contentType && !str_starts_with($contentType, 'text/html')) {
            return;
        }
        $content = $response->getContent();
        if (false === $content) {
            return;
        }

        $origin = $this->renderer->origin();
        if ('' === $origin) {
            return;
        }

        $updated = $this->insertBefore(
            $content,
            '</head>',
            $this->renderer->headBlock(),
            Breadcrumb::headGuard(),
            false,
        );
        if ($this->breadcrumb->injectsAnchor()) {
            $updated = $this->insertBefore(
                $updated,
                '</body>',
                $this->renderer->anchor(),
                Breadcrumb::anchorGuard($origin),
                true,
            );
        }

        if ($updated === $content) {
            return;
        }

        $response->setContent($updated);
        // One call covers both insertions: it removes headers, so doing it once
        // after the last mutation leaves exactly the same result as doing it
        // after each, and it is unreachable unless something actually changed.
        BodyMetadata::invalidate($response);
    }

    /**
     * Insert markup immediately before a closing tag, skipping the work when
     * `$guard` says the page already carries this half.
     *
     * The guard is a short stable substring rather than the rendered markup:
     * a hand-placed Twig call renders against the same cache, but an HTML
     * minifier, a Twig whitespace trim, or simply a hostname that flipped since
     * the customer pasted the Studio snippet all defeat a byte-exact
     * comparison — and defeating it means injecting a *second* copy, which is
     * the failure this check exists to prevent.
     *
     * @param bool $last match the final occurrence — right for ``</body>``,
     *                   which can legitimately appear escaped in page content,
     *                   whereas the first ``</head>`` is the document's own
     */
    private function insertBefore(string $content, string $tag, string $markup, string $guard, bool $last): string
    {
        if ('' === $markup || ('' !== $guard && str_contains($content, $guard))) {
            return $content;
        }
        $position = $last ? strripos($content, $tag) : stripos($content, $tag);
        if (false === $position) {
            return $content;
        }

        return substr_replace($content, $markup, $position, 0);
    }
}
