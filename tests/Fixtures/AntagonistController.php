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

    public function handle(string $path): Response
    {
        if ('' === $path) {
            return new Response(self::HOMEPAGE_HTML, 200, ['Content-Type' => 'text/html; charset=utf-8']);
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
