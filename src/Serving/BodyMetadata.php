<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-describes a response body this bundle has rewritten.
 *
 * Metadata derived from the original representation stops being true the moment
 * the injected JSON-LD, discovery links or robots block change the bytes. Most
 * of it can only be dropped — but the entity-tag can be *restored*, and that is
 * worth the trouble, because dropping it is what costs an application its
 * conditional GETs.
 *
 * ## The entity-tag
 *
 * An ETag names a representation. We changed the representation, so the old tag
 * is wrong; but the new one is computable — it is a digest of the bytes we are
 * about to send. So the tag is recomputed rather than discarded, preserving the
 * weak/strong flavour the application chose.
 *
 * Recomputing alone would not give anything back, though. The application's own
 * conditional check (a controller's ``isNotModified()``, say) runs *before*
 * kernel.response and compares the client's ``If-None-Match`` against the
 * application's pre-injection tag, which can never match the post-injection tag
 * a client actually holds. The result would be a correct-looking ETag that
 * revalidates to a full 200 every single time. So when the caller can supply the
 * request, the revalidation is redone here against the tag we just computed, and
 * a match becomes a real 304 — which is the whole point of keeping the tag.
 *
 * A response that carried **no** ETag is left without one. Inventing an
 * entity-tag would impose a caching contract the application never opted into,
 * on a body whose stability we cannot vouch for.
 *
 * Shared by {@see HeadInjector}, {@see RobotsFilter} and
 * {@see BreadcrumbInjector} — every place that rewrites a response the
 * application produced — so the behaviour cannot drift between them. Two of
 * those can mutate the same response in turn; each call recomputes from the
 * body as it stands, so the last one to run leaves the tag describing the bytes
 * that are actually sent.
 */
final class BodyMetadata
{
    /**
     * Metadata about the old bytes that has no computable replacement.
     *
     * ``Last-Modified`` is dropped rather than restamped: we know the body
     * changed, not when the resource did, and inventing "now" would license a
     * client to treat an unchanged resource as freshly modified. The digest
     * headers are dropped because getting a structured-field digest subtly wrong
     * is worse than omitting it. ``Content-Length`` is recomputed by
     * {@see Response::prepare()} on the way out.
     *
     * @var list<string>
     */
    private const DROPPED = [
        'Content-Length',
        'Last-Modified',
        'Content-MD5',
        'Digest',
        'Content-Digest',
        'Repr-Digest',
    ];

    /**
     * @param Request|null $request when given, a client whose ``If-None-Match``
     *                              matches the recomputed tag gets a 304
     */
    public static function invalidate(Response $response, ?Request $request = null): void
    {
        $etag = $response->getEtag();

        foreach (self::DROPPED as $header) {
            $response->headers->remove($header);
        }

        if (null === $etag) {
            return;
        }

        $content = $response->getContent();
        if (false === $content) {
            // Nothing to digest — a streamed or file-backed body. The old tag
            // describes bytes we have already changed, so it cannot stay.
            $response->headers->remove('ETag');

            return;
        }

        // Weak stays weak: the application chose to promise semantic
        // equivalence rather than byte equality, and injecting a discovery link
        // does not turn that into a stronger promise.
        $response->setEtag(hash('sha256', $content), str_starts_with($etag, 'W/'));

        // Redo the revalidation the application could only do against its own,
        // now-superseded tag. isNotModified() gates on a cacheable method itself
        // and turns a match into a 304; Last-Modified is already gone, so only
        // If-None-Match can decide it.
        if (null !== $request) {
            $response->isNotModified($request);
        }
    }
}
