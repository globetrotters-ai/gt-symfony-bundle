<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\AnalyticsState;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\DroppedCounter;
use Globetrotters\AiPresenceBundle\Analytics\Event;
use Globetrotters\AiPresenceBundle\Analytics\EventBuffer;
use Globetrotters\AiPresenceBundle\Analytics\Flusher;
use Globetrotters\AiPresenceBundle\Analytics\FlushGate;
use Globetrotters\AiPresenceBundle\Analytics\NdjsonEventStore;
use Globetrotters\AiPresenceBundle\Serving\OpportunisticFlushSubscriber;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeIngestTransport;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class OpportunisticFlushSubscriberTest extends TestCase
{
    private string $dir;
    private BufferDirectory $directory;
    private EventBuffer $buffer;
    private FakeIngestTransport $transport;
    private FlushGate $gate;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
        $this->directory = new BufferDirectory($this->dir);
        $this->buffer = new EventBuffer(new NdjsonEventStore($this->directory), new DroppedCounter($this->directory));
        $this->transport = new FakeIngestTransport();
        $this->clock = new MockClock('2026-08-11 09:00:00');
        $this->gate = new FlushGate($this->directory, $this->clock);
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    public function testRunsAfterCaptureSoThisRequestsEventCanGoOutInThisFlush(): void
    {
        $events = OpportunisticFlushSubscriber::getSubscribedEvents();

        self::assertSame(['onKernelTerminate', -256], $events[KernelEvents::TERMINATE]);
    }

    /**
     * The whole point of this lane: an application with no cron entry, no
     * systemd unit and no messenger:consume still reports.
     */
    public function testFlushesWithNoWorkerAndNoCron(): void
    {
        $this->fill(2);

        $this->subscriber()->onKernelTerminate($this->terminate());

        self::assertCount(1, $this->transport->sent);
        self::assertSame(0, $this->buffer->count());
    }

    public function testSendsOneBatchRatherThanDrainingLikeTheConsoleLane(): void
    {
        $this->fill(2500);

        $this->subscriber()->onKernelTerminate($this->terminate());

        self::assertCount(1, $this->transport->sent, 'a web worker holding a socket open is a different trade');
        self::assertSame(1500, $this->buffer->count());
    }

    public function testFiresAtMostOncePerIntervalHoweverMuchTrafficArrives(): void
    {
        $subscriber = $this->subscriber();
        $this->fill(1);
        $subscriber->onKernelTerminate($this->terminate());

        foreach (range(1, 20) as $ignored) {
            $this->fill(1);
            $subscriber->onKernelTerminate($this->terminate());
        }

        self::assertCount(1, $this->transport->sent);

        $this->clock->sleep(FlushGate::INTERVAL_SECONDS);
        $subscriber->onKernelTerminate($this->terminate());

        self::assertCount(2, $this->transport->sent);
    }

    public function testStaysDormantWhenAnotherLaneAlreadyFlushed(): void
    {
        // A cron'd gt:presence:flush stamps the same file, so the fallback
        // finds the interval already satisfied and does nothing.
        $this->fill(1);
        $this->gate->stamp();

        $this->subscriber()->onKernelTerminate($this->terminate());

        self::assertCount(0, $this->transport->sent);
    }

    public function testDoesNothingWithAnEmptyBuffer(): void
    {
        $this->subscriber()->onKernelTerminate($this->terminate());

        self::assertCount(0, $this->transport->sent);
        self::assertNull($this->gate->lastAttemptAt(), 'not even a stat-and-stamp when there is nothing to send');
    }

    public function testCanBeTurnedOff(): void
    {
        $this->fill(1);

        $this->subscriber($this->options(opportunistic: false))->onKernelTerminate($this->terminate());

        self::assertCount(0, $this->transport->sent);
    }

    public function testDoesNothingWithoutCredentials(): void
    {
        $this->fill(1);

        $this->subscriber(new AnalyticsOptions(true, '', '', true, false))->onKernelTerminate($this->terminate());

        self::assertCount(0, $this->transport->sent);
    }

    private function subscriber(?AnalyticsOptions $options = null): OpportunisticFlushSubscriber
    {
        $options ??= $this->options();

        return new OpportunisticFlushSubscriber(
            new Flusher(
                $this->buffer,
                $this->transport,
                $options,
                new AnalyticsState(new ArrayAdapter()),
                $this->gate,
                $this->clock,
            ),
            $this->buffer,
            $options,
            $this->gate,
        );
    }

    private function options(bool $opportunistic = true): AnalyticsOptions
    {
        return new AnalyticsOptions(true, 'https://api.test/ingest', 'token', $opportunistic, false);
    }

    private function terminate(): TerminateEvent
    {
        return new TerminateEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/llms.txt'),
            new Response('body'),
        );
    }

    private function fill(int $count): void
    {
        static $seq = 0;

        foreach (range(1, $count) as $ignored) {
            $this->buffer->append(new Event(
                \sprintf('00000000-0000-4000-8000-%012d', ++$seq),
                '2026-08-11T09:14:22Z',
                '/llms.txt',
                'ClaudeBot/1.0',
                '160.79.104.10',
                '',
                200,
                4211,
            ));
        }
    }
}
