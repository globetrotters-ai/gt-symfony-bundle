<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\AnalyticsState;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\ClientIpResolver;
use Globetrotters\AiPresenceBundle\Analytics\DroppedCounter;
use Globetrotters\AiPresenceBundle\Analytics\EventBuffer;
use Globetrotters\AiPresenceBundle\Analytics\EventRecorder;
use Globetrotters\AiPresenceBundle\Analytics\NdjsonEventStore;
use Globetrotters\AiPresenceBundle\Serving\ArtefactCaptureSubscriber;
use Globetrotters\AiPresenceBundle\Serving\Router;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ArtefactCaptureSubscriberTest extends TestCase
{
    private string $dir;
    private EventBuffer $buffer;
    private ArtefactCaptureSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
        $directory = new BufferDirectory($this->dir);
        $this->buffer = new EventBuffer(new NdjsonEventStore($directory), new DroppedCounter($directory));

        $options = new AnalyticsOptions(true, 'https://api.test/ingest', 'token', true, false);
        $this->subscriber = new ArtefactCaptureSubscriber(new EventRecorder(
            $this->buffer,
            $options,
            new ClientIpResolver($options),
            new AnalyticsState(new ArrayAdapter()),
            new MockClock('2026-08-11 09:14:22'),
        ));
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    public function testCapturesOnTerminateRatherThanOnTheServePath(): void
    {
        // Terminate runs after the response has been sent, so buffering costs
        // the agent nothing.
        self::assertSame(['onKernelTerminate', 0], ArtefactCaptureSubscriber::getSubscribedEvents()[KernelEvents::TERMINATE]);
    }

    public function testRecordsOneEventPerServedArtefactRequest(): void
    {
        $this->subscriber->onKernelTerminate($this->terminate('/llms.txt', 4211, new Response('body', 200)));

        self::assertSame(1, $this->buffer->count());
        $event = $this->buffer->claim(1)[0];
        self::assertSame('/llms.txt', $event->path());
        self::assertSame(200, $event->status());
        self::assertSame(4211, $event->bytes());
    }

    public function testTakesTheStatusFromTheFinalResponse(): void
    {
        $this->subscriber->onKernelTerminate($this->terminate('/ai.json', 12, new Response('', 503)));

        self::assertSame(503, $this->buffer->claim(1)[0]->status());
    }

    public function testIgnoresRequestsThisBundleDidNotServe(): void
    {
        $event = new TerminateEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/some/page'),
            new Response('page'),
        );

        $this->subscriber->onKernelTerminate($event);

        self::assertSame(0, $this->buffer->count());
    }

    private function terminate(string $path, int $bytes, Response $response): TerminateEvent
    {
        $request = Request::create($path, server: ['REMOTE_ADDR' => '160.79.104.10']);
        $request->attributes->set(Router::ATTRIBUTE_PATH, $path);
        $request->attributes->set(Router::ATTRIBUTE_BYTES, $bytes);

        return new TerminateEvent($this->createStub(HttpKernelInterface::class), $request, $response);
    }
}
