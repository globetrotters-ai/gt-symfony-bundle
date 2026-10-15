<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\AnalyticsState;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\CaptureContext;
use Globetrotters\AiPresenceBundle\Analytics\ClientIpResolver;
use Globetrotters\AiPresenceBundle\Analytics\DroppedCounter;
use Globetrotters\AiPresenceBundle\Analytics\EventBuffer;
use Globetrotters\AiPresenceBundle\Analytics\NdjsonEventStore;
use Globetrotters\AiPresenceBundle\Analytics\SiteCapture;
use Globetrotters\AiPresenceBundle\Analytics\VendorRanges;
use Globetrotters\AiPresenceBundle\Serving\Router;
use Globetrotters\AiPresenceBundle\Serving\SiteCaptureSubscriber;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeIngestTransport;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SiteCaptureSubscriberTest extends TestCase
{
    private string $dir;
    private EventBuffer $buffer;
    private AnalyticsState $state;
    private SiteCaptureSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
        $directory = new BufferDirectory($this->dir);
        $this->buffer = new EventBuffer(new NdjsonEventStore($directory), new DroppedCounter($directory));
        $this->state = new AnalyticsState(new ArrayAdapter());
        $clock = new MockClock('2026-10-15 10:00:00', 'UTC');
        $options = new AnalyticsOptions(true, 'https://api.globetrotters.ai/presence/analytics/server-log', 'token', true, false);
        $ranges = new VendorRanges($directory, new FakeIngestTransport(), $options, $this->state, $clock);

        $this->subscriber = new SiteCaptureSubscriber(
            new SiteCapture($this->buffer, $options, new ClientIpResolver($options), $ranges, $clock),
            new CaptureContext($options, $this->state, $ranges),
        );
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    public function testCapturesAnUnmarkedBotPage(): void
    {
        $this->terminate($this->request());

        self::assertSame(1, $this->buffer->count());
        self::assertSame(['cf-ray'], $this->state->state()['cdn_markers']);
    }

    public function testSkipsEverythingTheBundleAnsweredItself(): void
    {
        foreach ([Router::ATTRIBUTE_PATH => '/llms.txt', Router::ATTRIBUTE_KEY => true, Router::ATTRIBUTE_SITEMAP => true, Router::ATTRIBUTE_PAGE_VIEW => true] as $attribute => $value) {
            $request = $this->request();
            $request->attributes->set($attribute, $value);
            $this->terminate($request);
        }

        self::assertSame(0, $this->buffer->count());
        self::assertSame(['cf-ray'], $this->state->state()['cdn_markers'], 'a served artefact still feeds the context');
    }

    public function testAnUnconfiguredInstallWritesNoContextState(): void
    {
        $directory = new BufferDirectory($this->dir);
        $options = new AnalyticsOptions(true, '', '', true, false);
        $ranges = new VendorRanges($directory, new FakeIngestTransport(), $options, $this->state, new MockClock('2026-10-15 10:00:00', 'UTC'));
        $subscriber = new SiteCaptureSubscriber(
            new SiteCapture($this->buffer, $options, new ClientIpResolver($options), $ranges, new MockClock('2026-10-15 10:00:00', 'UTC')),
            new CaptureContext($options, $this->state, $ranges),
        );

        $served = $this->request();
        $served->attributes->set(Router::ATTRIBUTE_PATH, '/llms.txt');
        foreach ([$served, $this->request()] as $request) {
            $subscriber->onKernelTerminate(new TerminateEvent($this->createStub(HttpKernelInterface::class), $request, $this->html()));
        }

        self::assertSame(0, $this->buffer->count());
        self::assertSame([], $this->state->state()['cdn_markers']);
    }

    /**
     * kernel.terminate only ever fires for the main request, so a sub-request
     * (an embedded controller, an ESI fragment) cannot reach the subscriber.
     */
    public function testTheTerminateEventIsAlwaysTheMainRequest(): void
    {
        self::assertTrue((new TerminateEvent($this->createStub(HttpKernelInterface::class), $this->request(), $this->html()))->isMainRequest());
    }

    public function testTheSubscriptionRunsOnTerminate(): void
    {
        self::assertSame(['kernel.terminate' => ['onKernelTerminate', 0]], SiteCaptureSubscriber::getSubscribedEvents());
    }

    private function terminate(Request $request): void
    {
        $this->subscriber->onKernelTerminate(new TerminateEvent($this->createStub(HttpKernelInterface::class), $request, $this->html()));
    }

    private function request(): Request
    {
        return Request::create('/visiter', server: ['HTTP_USER_AGENT' => 'GPTBot/1.2', 'HTTP_CF_RAY' => 'x']);
    }

    private function html(): Response
    {
        return new Response('<html></html>', 200, ['Content-Type' => 'text/html']);
    }
}
