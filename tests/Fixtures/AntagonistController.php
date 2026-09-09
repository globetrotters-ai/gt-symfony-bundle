<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Catch-all controller playing the CMS-overlay antagonist: it claims every
 * path (including the artefact paths), proving the Router subscriber
 * pre-empts routing on cache hits and falls through cleanly on misses.
 */
final class AntagonistController
{
    public const HOMEPAGE_HTML = '<html><head><title>Demo</title></head><body>Homepage</body></html>';

    /**
     * A full HTML interior page. The plain-text catch-all below has no <head>,
     * so it cannot exercise a subscriber that injects into one — and the
     * site-wide discovery relations are precisely a claim about interior pages.
     */
    public const INTERIOR_HTML = '<html><head><title>Interior</title></head><body>Interior</body></html>';

    public function handle(string $path): Response
    {
        if ('' === $path) {
            return new Response(self::HOMEPAGE_HTML, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        if ('interior' === $path) {
            return new Response(self::INTERIOR_HTML, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        if ('etagged' === $path) {
            // An application that publishes an entity-tag and serves conditional
            // GETs — the case body rewriting used to break.
            $response = new Response(self::INTERIOR_HTML, 200, ['Content-Type' => 'text/html; charset=utf-8']);
            $response->setEtag('app-representation-v1');

            return $response;
        }
        if ('robots.txt' === $path) {
            throw new NotFoundHttpException('No robots.txt route.');
        }

        return new Response('ANTAGONIST', 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function robots(): Response
    {
        return new Response("User-agent: *\nDisallow: /admin\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
