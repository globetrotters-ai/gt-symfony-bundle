<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\AnalyticsState;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\RangesFetch;
use Globetrotters\AiPresenceBundle\Analytics\VendorRanges;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeIngestTransport;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class VendorRangesTest extends TestCase
{
    public const ENDPOINT = 'https://api.globetrotters.ai/presence/analytics/server-log';
    public const TOKEN = 'ingest-token-value';

    public const LIST = '{"version":"3f2a9c1e0b7d","generatedAt":"2026-10-14T03:00:00Z","ranges":['
        .'{"platform":"chatgpt","start":"20.171.206.0","end":"20.171.206.255"},'
        .'{"platform":"claude","start":"2600:1f28:365:80b0::","end":"2600:1f28:365:80b0:ffff:ffff:ffff:ffff"},'
        .'{"platform":"broken","start":"not-an-ip","end":"1.2.3.4"}]}';

    private string $dir;
    private FakeIngestTransport $transport;
    private AnalyticsState $state;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
        $this->transport = new FakeIngestTransport();
        $this->state = new AnalyticsState(new ArrayAdapter());
        $this->clock = new MockClock('2026-10-15 10:00:00', 'UTC');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    public function testNoListMeansUnverified(): void
    {
        self::assertSame(['verified' => null, 'vendor' => null], $this->ranges()->verify('20.171.206.10'));
    }

    public function testFetchesWithTheTokenAndMatchesIpv4AndIpv6(): void
    {
        $this->transport->willReturnRanges(RangesFetch::http(200, self::LIST));
        $ranges = $this->ranges();

        self::assertTrue($ranges->refreshIfDue());
        self::assertSame(self::ENDPOINT.'/ranges', $this->transport->rangeRequests[0]['url']);
        self::assertSame(self::TOKEN, $this->transport->rangeRequests[0]['token']);
        self::assertSame('', $this->transport->rangeRequests[0]['version']);

        self::assertSame(['verified' => true, 'vendor' => 'chatgpt'], $ranges->verify('20.171.206.10'));
        self::assertSame(['verified' => false, 'vendor' => null], $ranges->verify('20.171.207.1'));
        self::assertSame(['verified' => true, 'vendor' => 'claude'], $ranges->verify('2600:1f28:365:80b0::42'));
        self::assertSame(['verified' => false, 'vendor' => null], $ranges->verify('2600:1f28:365:80b1::1'));
        self::assertSame(['verified' => false, 'vendor' => null], $ranges->verify(''));
        self::assertSame(2, $ranges->count(), 'a malformed range is dropped');
        self::assertSame('3f2a9c1e0b7d', $ranges->version());
        self::assertSame('2026-10-15T10:00:00Z', $ranges->fetchedAtIso());

        // A fresh process reads the same file.
        self::assertSame(['verified' => true, 'vendor' => 'chatgpt'], $this->ranges()->verify('20.171.206.1'));
    }

    public function testAtMostOnceADayAndConditional(): void
    {
        $this->transport->willReturnRanges(RangesFetch::http(200, self::LIST));
        $this->ranges()->refreshIfDue();

        $this->clock->modify('+23 hours');
        self::assertFalse($this->ranges()->refreshIfDue());
        self::assertCount(1, $this->transport->rangeRequests);

        $this->clock->modify('+1 hour');
        $this->transport->willReturnRanges(RangesFetch::http(304));
        $ranges = $this->ranges();
        self::assertTrue($ranges->refreshIfDue());
        self::assertSame('3f2a9c1e0b7d', $this->transport->rangeRequests[1]['version']);
        self::assertSame('2026-10-16T10:00:00Z', $ranges->fetchedAtIso(), 'a 304 confirms the list as fresh');
        self::assertSame('', $ranges->lastError());
    }

    public function testAFailureKeepsThePreviousList(): void
    {
        $this->transport->willReturnRanges(RangesFetch::http(200, self::LIST));
        $this->ranges()->refreshIfDue();

        foreach ([RangesFetch::http(500), RangesFetch::error('timeout'), RangesFetch::http(200, '<html>maintenance</html>'), RangesFetch::http(401)] as $failure) {
            $this->clock->modify('+1 day');
            $this->transport->willReturnRanges($failure);
            $ranges = $this->ranges();
            $ranges->refreshIfDue();

            self::assertNotSame('', $ranges->lastError());
            self::assertSame('3f2a9c1e0b7d', $ranges->version());
            self::assertSame('2026-10-15T10:00:00Z', $ranges->fetchedAtIso());
            self::assertSame(['verified' => true, 'vendor' => 'chatgpt'], $ranges->verify('20.171.206.10'));
        }
    }

    public function testAStaleListMeansUnverified(): void
    {
        $this->transport->willReturnRanges(RangesFetch::http(200, self::LIST));
        $this->ranges()->refreshIfDue();

        $this->clock->modify('+14 days');
        self::assertSame(['verified' => true, 'vendor' => 'chatgpt'], $this->ranges()->verify('20.171.206.10'), '14 days is still fresh');

        $this->clock->modify('+1 second');
        $ranges = $this->ranges();
        self::assertTrue($ranges->isStale());
        self::assertSame(['verified' => null, 'vendor' => null], $ranges->verify('20.171.206.10'));
        self::assertSame(['verified' => null, 'vendor' => null], $ranges->verify('8.8.8.8'));
    }

    public function testResetPicksUpAListAnotherProcessReplaced(): void
    {
        $this->transport->willReturnRanges(RangesFetch::http(200, self::LIST));
        $this->ranges()->refreshIfDue();
        $worker = $this->ranges();
        self::assertSame('3f2a9c1e0b7d', $worker->version());

        $this->clock->modify('+1 day');
        $this->transport->willReturnRanges(RangesFetch::http(200, str_replace('3f2a9c1e0b7d', 'a1b2c3d4e5f6', self::LIST)));
        self::assertTrue($this->ranges()->refreshIfDue());

        self::assertSame('3f2a9c1e0b7d', $worker->version(), 'held for the request');
        $worker->reset();
        self::assertSame('a1b2c3d4e5f6', $worker->version());
        self::assertSame('2026-10-16T10:00:00Z', $worker->fetchedAtIso());
    }

    public function testNothingIsFetchedWhileReportingIsNotConfigured(): void
    {
        $ranges = new VendorRanges(
            new BufferDirectory($this->dir),
            $this->transport,
            new AnalyticsOptions(true, '', '', true, false),
            $this->state,
            $this->clock,
        );

        self::assertFalse($ranges->refreshIfDue(true));
        self::assertSame([], $this->transport->rangeRequests);
    }

    private function ranges(): VendorRanges
    {
        return new VendorRanges(
            new BufferDirectory($this->dir),
            $this->transport,
            new AnalyticsOptions(true, self::ENDPOINT, self::TOKEN, true, false),
            $this->state,
            $this->clock,
        );
    }
}
