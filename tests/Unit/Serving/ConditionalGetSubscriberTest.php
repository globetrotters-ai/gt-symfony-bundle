<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Serving\BodyMetadata;
use Globetrotters\AiPresenceBundle\Serving\ConditionalGetSubscriber;
use Globetrotters\AiPresenceBundle\Serving\HeadInjector;
use Globetrotters\AiPresenceBundle\Serving\RobotsFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ConditionalGetSubscriberTest extends TestCase
{
    private function revalidate(
        Request $request,
        Response $response,
        int $type = HttpKernelInterface::MAIN_REQUEST,
    ): Response {
        $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, $type, $response);
        (new ConditionalGetSubscriber())->onKernelResponse($event);

        return $event->getResponse();
    }

    private function marked(string $ifNoneMatch = ''): Request
    {
        $request = Request::create('/');
        if ('' !== $ifNoneMatch) {
            $request->headers->set('If-None-Match', $ifNoneMatch);
        }
        $request->attributes->set(BodyMetadata::ATTRIBUTE_REWRITTEN, true);

        return $request;
    }

    public function testRunsBelowEveryRewriter(): void
    {
        $priority = ConditionalGetSubscriber::PRIORITY;

        self::assertLessThan(-20, $priority, 'must run after RobotsFilter');
        self::assertSame([-20, 0], array_column(RobotsFilter::getSubscribedEvents(), 1));
        self::assertSame(-10, HeadInjector::getSubscribedEvents()['kernel.response'][1]);
    }

    public function testMatchingTagBecomesA304(): void
    {
        $etag = '"'.hash('sha256', 'final body').'"';
        $response = new Response('final body', 200, ['ETag' => $etag]);

        $result = $this->revalidate($this->marked($etag), $response);

        self::assertSame(304, $result->getStatusCode());
        self::assertEmpty((string) $result->getContent());
    }

    /**
     * The regression this subscriber exists for. A client that cached an
     * *intermediate* representation — the homepage after the JSON-LD injection
     * but before the breadcrumb one, which is exactly what a `full_apex` install
     * serves — must not be told "not modified" once the breadcrumb is switched
     * on, or it would never receive it.
     */
    public function testAnIntermediateTagDoesNotRevalidate(): void
    {
        $intermediate = '"'.hash('sha256', '<html>jsonld</html>').'"';
        $final = new Response('<html>jsonld+breadcrumb</html>', 200, [
            'ETag' => '"'.hash('sha256', '<html>jsonld+breadcrumb</html>').'"',
        ]);

        $result = $this->revalidate($this->marked($intermediate), $final);

        self::assertSame(200, $result->getStatusCode());
        self::assertStringContainsString('breadcrumb', (string) $result->getContent());
    }

    public function testUntouchedResponsesAreLeftAlone(): void
    {
        $etag = '"'.hash('sha256', 'body').'"';
        $request = Request::create('/');
        $request->headers->set('If-None-Match', $etag);

        $result = $this->revalidate($request, new Response('body', 200, ['ETag' => $etag]));

        self::assertSame(200, $result->getStatusCode(), 'a response this bundle never rewrote is none of our business');
    }

    public function testSubRequestsAreLeftAlone(): void
    {
        $etag = '"'.hash('sha256', 'body').'"';
        $response = new Response('body', 200, ['ETag' => $etag]);

        $result = $this->revalidate($this->marked($etag), $response, HttpKernelInterface::SUB_REQUEST);

        self::assertSame(200, $result->getStatusCode());
    }

    public function testUnsafeMethodsNeverRevalidate(): void
    {
        $etag = '"'.hash('sha256', 'body').'"';
        $request = Request::create('/', 'POST');
        $request->headers->set('If-None-Match', $etag);
        $request->attributes->set(BodyMetadata::ATTRIBUTE_REWRITTEN, true);

        self::assertSame(200, $this->revalidate($request, new Response('body', 200, ['ETag' => $etag]))->getStatusCode());
    }
}
