<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Puts this bundle's discovery URLs into the site's ``/sitemap.xml``:
 * decorates the application's own when it serves one, and generates the whole
 * document when it would 404. The same decorate-or-generate shape
 * {@see RobotsFilter} uses for the other file an application may own.
 *
 * **Why it merges rather than standing aside.** The readiness validator's
 * ``sitemap_includes_llms`` probe fetches a hardcoded ``{apex}/sitemap.xml``
 * and fails unless a ``<loc>`` names ``llms.txt`` or ``llms-full.txt``. It
 * never reads the ``Sitemap:`` line, so a sitemap served at a path of our own
 * would be invisible to it — and most apexes already serve a
 * ``/sitemap.xml``, so an install that stood aside would be the common case
 * doing nothing. The backend gives integrators merging by hand exactly the
 * same instruction. Decoration is additive: entries are inserted before the
 * closing tag, nothing is removed, and a URL the document already lists is
 * skipped.
 *
 * This is why the sitemap is not a {@see Router} route. Router runs on
 * kernel.request at priority 64, ahead of routing, precisely so nothing can
 * claim the artefact paths — pre-emption is the point there, and no
 * application serves ``/llms.txt`` or an IndexNow key file. Plenty of them
 * serve ``/sitemap.xml``, and replacing that with a six-URL listing of
 * discovery documents would be a straight loss; it has to be reached on
 * kernel.response, where the application's own document is in hand.
 *
 * {@see Sitemap::decorate()} refuses anything that is not a plain, complete,
 * reasonably sized ``<urlset>`` — a ``<sitemapindex>``, a streamed or
 * file-backed body, an oversized one — and leaves it exactly as it is.
 *
 * Same limitation as robots: a physical ``public/sitemap.xml`` is served by
 * the web server and never reaches the kernel, so it cannot be decorated here.
 */
final class SitemapFilter implements EventSubscriberInterface
{
    private const PATH = '/'.Sitemap::PATH;

    public function __construct(
        private readonly Options $options,
        private readonly ArtefactCache $cache,
        private readonly Sitemap $sitemap,
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
     * Decorate the application's own sitemap, or generate one when it returned
     * an explicit 404 Response (a catch-all controller that returns rather
     * than throws — the thrown case is handled in onKernelException instead).
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->applies($request)) {
            return;
        }

        $response = $event->getResponse();
        $status = $response->getStatusCode();
        // Symfony's ResponseListener runs at priority 0, above this one, and its
        // Response::prepare() has already emptied the body of a HEAD response.
        // Writing one back here would put bytes on a HEAD — and in the 200
        // branch there is no longer a document to decorate. The exception lane
        // below is prepared *after* it sets the response, so it needs no such
        // guard.
        $isHead = 'HEAD' === $request->getMethod();
        $origin = $request->getSchemeAndHttpHost();

        if (200 === $status) {
            if ($isHead || !self::isXml($response)) {
                return;
            }
            $content = $response->getContent();
            if (false === $content) {
                // Streamed or file-backed: there are no bytes to merge into.
                return;
            }
            $decorated = $this->sitemap->decorate($content, $origin);
            if (null === $decorated) {
                return;
            }

            $response->setContent($decorated);
            BodyMetadata::invalidate($response, $request);

            return;
        }

        if (404 !== $status) {
            return;
        }

        $body = $this->sitemap->render($origin);
        if ('' === $body) {
            return;
        }

        $response->setStatusCode(200);
        $response->setContent($isHead ? '' : $body);
        $response->headers->add(self::headers());
        BodyMetadata::invalidate($response, $request);
    }

    /**
     * Whether the application called this response XML at all.
     *
     * A ``Content-Encoding`` means the bytes in hand are compressed, not
     * markup — merging into them would corrupt the response.
     */
    private static function isXml(Response $response): bool
    {
        if ($response->headers->has('Content-Encoding')) {
            return false;
        }
        $contentType = (string) $response->headers->get('Content-Type');

        return str_starts_with($contentType, 'application/xml')
            || str_starts_with($contentType, 'text/xml');
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->applies($request)) {
            return;
        }
        if (!$event->getThrowable() instanceof NotFoundHttpException) {
            return;
        }

        $body = $this->sitemap->render($request->getSchemeAndHttpHost());
        if ('' === $body) {
            return;
        }

        // Without this the kernel would force the response status back to the
        // exception's 404 (see HttpKernel::handleThrowable).
        $event->allowCustomResponseCode();
        $event->setResponse(new Response($body, 200, self::headers()));
    }

    /**
     * Headers for a document this bundle generated whole. The body embeds the
     * origin this very request arrived on, so it must not be reused for
     * another: a host alias sharing a cache entry would be advertised a
     * sitemap full of the other host's URLs. Same constant the artefacts use,
     * for a different reason — generating the document costs one cache read
     * and no I/O, so there is nothing to protect by caching it.
     *
     * A *decorated* response keeps the application's own headers: it is their
     * document and their caching contract, and this bundle only adds entries
     * to it.
     *
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return ['Content-Type' => Sitemap::CONTENT_TYPE] + Router::NO_STORE_HEADERS;
    }

    /**
     * Cheap structural tests first: the cache and state reads only happen for a
     * request that could actually be answered here.
     */
    private function applies(Request $request): bool
    {
        return self::PATH === $request->getPathInfo()
            && \in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && $this->options->isConnected()
            && $this->cache->hasAny();
    }
}
