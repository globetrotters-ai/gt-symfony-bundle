<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Links the apex back to the presence published at the Globetrotters host, for
 * the ``subdomain_breadcrumb`` install profile only.
 *
 * **Head markup only, never anything visible.** Installing this bundle has to be
 * transparent to the end user: it must not put an element into a page whose
 * design it does not own, at a position it cannot know is safe. So the automatic
 * injection is limited to ``<link>`` relations inside ``<head>``, which change
 * nothing a visitor sees. Matches ``Serving\Breadcrumbs`` in
 * gt-wordpress-plugin, which hooks ``wp_head`` and nothing else.
 *
 * The cost is real and worth stating: a ``<link>`` is a discovery pointer, not a
 * followed link, so on its own it does not pass the crawl authority that would
 * let the subdomain inherit the apex's standing. The visible anchor that *would*
 * is available as ``{{ gt_ai_presence_breadcrumb_link() }}`` for an integrator
 * to place inside their own layout, where they can see it and style it. It is
 * their markup and their call, which is exactly why this class does not make it.
 *
 * Under {@see \Globetrotters\AiPresenceBundle\Settings\Profile::FullApex} this
 * subscriber does nothing at all.
 *
 * **Every HTML page, not only the homepage.** The three agent-discovery
 * relations name where the site's surfaces live, which is true from any page,
 * and an agent that lands on a deep page is exactly the case that needs them.
 * Only ``rel="alternate"`` is homepage-scoped, and {@see BreadcrumbRenderer}
 * decides that per request. {@see HeadInjector}, which injects the JSON-LD
 * document itself, stays homepage-only.
 *
 * Running site-wide means rewriting far more responses, so
 * {@see BodyMetadata} recomputes the entity-tag from the injected body and
 * revalidates it against the request rather than dropping it — an application
 * serving conditional GETs keeps them. ``Last-Modified`` still goes, since a
 * body change says nothing about when the resource changed.
 *
 * Runs beside {@see HeadInjector} at the same priority and applies the same
 * remaining gates (main request, 200, HTML). Both subscribers mutate the body
 * independently, and each invalidates the metadata that describes it.
 *
 * The injection is idempotent against ``gt_ai_presence_breadcrumb_head()``, so
 * an integrator who places the head block themselves does not get it twice.
 */
final class BreadcrumbInjector implements EventSubscriberInterface
{
    public function __construct(private readonly BreadcrumbRenderer $renderer)
    {
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

        $breadcrumb = $this->renderer->render();
        if (null === $breadcrumb) {
            return;
        }

        $updated = $this->insertBefore($content, $breadcrumb->headBlock, Breadcrumb::headGuards());

        if ($updated === $content) {
            return;
        }

        $response->setContent($updated);
        // Unreachable unless something actually changed, so the entity-tag is
        // only recomputed when there are new bytes to describe.
        BodyMetadata::invalidate($response, $event->getRequest());
    }

    /**
     * Insert the block immediately before ``</head>``, skipping the work when a
     * guard says the page already carries it.
     *
     * The guard is a short stable substring rather than the rendered markup: an
     * HTML minifier, a Twig whitespace trim, or a hostname that flipped since
     * the customer pasted the Studio snippet all defeat a byte-exact comparison
     * — and defeating it means injecting a *second* copy, which is the failure
     * this check exists to prevent.
     *
     * @param list<string> $guards any match means "already present"
     */
    private function insertBefore(string $content, string $markup, array $guards): string
    {
        if ('' === $markup) {
            return $content;
        }
        foreach ($guards as $guard) {
            if ('' !== $guard && str_contains($content, $guard)) {
                return $content;
            }
        }
        $position = stripos($content, '</head>');
        if (false === $position) {
            return $content;
        }

        return substr_replace($content, $markup, $position, 0);
    }
}
