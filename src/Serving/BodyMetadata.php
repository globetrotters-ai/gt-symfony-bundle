<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Symfony\Component\HttpFoundation\Response;

/**
 * Drops the headers that describe a response body this bundle has rewritten.
 *
 * Metadata derived from the original representation stops being true the moment
 * the injected JSON-LD or robots block changes the bytes. In particular,
 * retaining an ETag lets a client receive 304 for an older representation.
 *
 * Shared by {@see HeadInjector} and {@see RobotsFilter} — the two places that
 * rewrite a response the application produced — so the list cannot drift.
 */
final class BodyMetadata
{
    /** @var list<string> */
    private const HEADERS = [
        'Content-Length',
        'ETag',
        'Last-Modified',
        'Content-MD5',
        'Digest',
        'Content-Digest',
        'Repr-Digest',
    ];

    public static function invalidate(Response $response): void
    {
        foreach (self::HEADERS as $header) {
            $response->headers->remove($header);
        }
    }
}
