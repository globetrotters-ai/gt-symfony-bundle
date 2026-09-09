<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

/**
 * Both halves of a breadcrumb, rendered together from a single read of the
 * cached ai.json.
 *
 * The point is that they cannot disagree. Deriving the origin once per response
 * and carrying it here means the head block and the anchor always name the same
 * host, and the anchor guard always matches the anchor actually rendered —
 * rather than depending on {@see \Globetrotters\AiPresenceBundle\Cache\ArtefactCache}
 * memoizing its reads for the life of the request, which is that class's
 * private business and not something this one should rely on.
 */
final class RenderedBreadcrumb
{
    public function __construct(
        public readonly string $origin,
        public readonly string $headBlock,
        public readonly string $anchor,
    ) {
    }
}
