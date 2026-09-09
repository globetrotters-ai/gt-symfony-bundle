<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Serving\BodyMetadata;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BodyMetadataTest extends TestCase
{
    private function rewritten(string $body = 'new body', array $headers = []): Response
    {
        return new Response($body, 200, $headers);
    }

    public function testDropsMetadataWithNoComputableReplacement(): void
    {
        $response = $this->rewritten('new body', [
            'Content-Length' => '3',
            'Last-Modified' => 'Mon, 08 Sep 2026 00:00:00 GMT',
            'Content-MD5' => 'abc',
            'Digest' => 'sha-256=abc',
            'Content-Digest' => 'sha-256=:abc:',
            'Repr-Digest' => 'sha-256=:abc:',
        ]);

        BodyMetadata::invalidate($response);

        foreach (['Content-Length', 'Last-Modified', 'Content-MD5', 'Digest', 'Content-Digest', 'Repr-Digest'] as $header) {
            self::assertFalse($response->headers->has($header), $header.' should be dropped');
        }
    }

    public function testRecomputesTheEtagFromTheNewBody(): void
    {
        $response = $this->rewritten('new body', ['ETag' => '"stale"']);

        BodyMetadata::invalidate($response);

        self::assertSame('"'.hash('sha256', 'new body').'"', $response->getEtag());
    }

    /**
     * A weak tag promises semantic equivalence, not byte equality. Injecting a
     * discovery link does not turn that into a stronger promise.
     */
    public function testPreservesTheWeakFlavour(): void
    {
        $response = $this->rewritten('new body', ['ETag' => 'W/"stale"']);

        BodyMetadata::invalidate($response);

        self::assertSame('W/"'.hash('sha256', 'new body').'"', $response->getEtag());
    }

    /**
     * Inventing an entity-tag would impose a caching contract the application
     * never opted into.
     */
    public function testDoesNotInventAnEtag(): void
    {
        $response = $this->rewritten();

        BodyMetadata::invalidate($response);

        self::assertNull($response->getEtag());
    }

    public function testTheRecomputedTagIsStableForTheSameBytes(): void
    {
        $first = $this->rewritten('same', ['ETag' => '"a"']);
        $second = $this->rewritten('same', ['ETag' => '"b"']);

        BodyMetadata::invalidate($first);
        BodyMetadata::invalidate($second);

        self::assertSame($first->getEtag(), $second->getEtag());
    }

    /**
     * The point of keeping the tag: a client holding the post-injection
     * representation revalidates to a real 304 rather than re-downloading.
     */
    public function testMatchingClientTagBecomesA304(): void
    {
        $etag = '"'.hash('sha256', 'new body').'"';
        $request = Request::create('/');
        $request->headers->set('If-None-Match', $etag);
        $response = $this->rewritten('new body', ['ETag' => '"stale"']);

        BodyMetadata::invalidate($response, $request);

        self::assertSame(304, $response->getStatusCode());
        self::assertSame($etag, $response->getEtag());
        self::assertEmpty((string) $response->getContent());
    }

    public function testNonMatchingClientTagStillGets200(): void
    {
        $request = Request::create('/');
        $request->headers->set('If-None-Match', '"something-else"');
        $response = $this->rewritten('new body', ['ETag' => '"stale"']);

        BodyMetadata::invalidate($response, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('new body', (string) $response->getContent());
    }

    /**
     * The application's own pre-injection tag is exactly what a client can never
     * be holding, so it must not revalidate.
     */
    public function testTheSupersededTagDoesNotRevalidate(): void
    {
        $request = Request::create('/');
        $request->headers->set('If-None-Match', '"stale"');
        $response = $this->rewritten('new body', ['ETag' => '"stale"']);

        BodyMetadata::invalidate($response, $request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testUnsafeMethodsNeverRevalidate(): void
    {
        $etag = '"'.hash('sha256', 'new body').'"';
        $request = Request::create('/', 'POST');
        $request->headers->set('If-None-Match', $etag);
        $response = $this->rewritten('new body', ['ETag' => '"stale"']);

        BodyMetadata::invalidate($response, $request);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Two subscribers can rewrite the same response in turn; the tag must
     * describe the bytes that are actually sent, not an intermediate state.
     */
    public function testTheLastRewriteWins(): void
    {
        $response = $this->rewritten('first', ['ETag' => '"stale"']);

        BodyMetadata::invalidate($response);
        $response->setContent('first and second');
        BodyMetadata::invalidate($response);

        self::assertSame('"'.hash('sha256', 'first and second').'"', $response->getEtag());
    }
}
