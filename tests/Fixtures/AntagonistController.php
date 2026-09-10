<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Request;
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

    public const APP_SITEMAP_XML = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://app.example/about</loc></url></urlset>';

    /**
     * A full HTML interior page. The plain-text catch-all below has no <head>,
     * so it cannot exercise a subscriber that injects into one — and the
     * site-wide discovery relations are precisely a claim about interior pages.
     */
    public const INTERIOR_HTML = '<html><head><title>Interior</title></head><body>Interior</body></html>';

    /**
     * The application's own robots.txt, by ``?fixture=`` name.
     */
    public const ROBOTS = [
        'wildcard' => "User-agent: *\nDisallow: /admin\n",
        'named' => "User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nDisallow: /admin\n",
        'full' => "User-agent: *\nDisallow: /\n",
        'signal-between' => "User-agent: *\nContent-Signal: ai-train=no\nUser-agent: GPTBot\nDisallow: /private\n",
    ];

    public const ROBOTS_ETAG = '"robots-v1"';

    /** Added late, by {@see LateValidatorListener}. */
    public const ROBOTS_LAST_MODIFIED = 'Wed, 21 Oct 2015 07:28:00 GMT';

    public function handle(Request $request, string $path): Response
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
            if ($request->query->has('return404')) {
                // A catch-all that returns its 404 rather than throwing it.
                return new Response('Not Found', 404, ['Content-Type' => 'text/html; charset=utf-8']);
            }

            throw new NotFoundHttpException('No robots.txt route.');
        }
        if ('sitemap.xml' === $path) {
            throw new NotFoundHttpException('No sitemap.xml route.');
        }

        return new Response('ANTAGONIST', 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * An application serving its robots.txt with validators, as a static-file
     * controller would: an exact Content-Length and an ETag here, and a
     * Last-Modified added late by {@see LateValidatorListener}.
     */
    public function robots(Request $request): Response
    {
        $body = self::ROBOTS[$request->query->getString('fixture', 'wildcard')] ?? self::ROBOTS['wildcard'];

        return new Response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Length' => (string) \strlen($body),
            'ETag' => self::ROBOTS_ETAG,
        ]);
    }

    /**
     * An application that owns its own sitemap — a manifest of its own pages,
     * which the bundle must leave alone.
     */
    public function sitemap(): Response
    {
        return new Response(self::APP_SITEMAP_XML, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }
}
