<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\AiReferrers;
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

    /** The same document as gt-backend serves it since the AI referrer list joined it. */
    public const LIST_WITH_REFERRERS = '{"version":"3f2a9c1e0b7d","generatedAt":"2026-10-14T03:00:00Z","ranges":['
        .'{"platform":"chatgpt","start":"20.171.206.0","end":"20.171.206.255"}],'
        .'"aiReferrers":{"hosts":{"chatgpt.com":"chatgpt","claude.ai":"claude"},"utmSources":{"perplexity":"perplexity","chatgpt.com":"chatgpt"},"searchHosts":["google.*"]}}';

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

    public function testAnIpv4MappedIpv6PeerMatchesItsIpv4Range(): void
    {
        $this->transport->willReturnRanges(RangesFetch::http(200, self::LIST));
        $ranges = $this->ranges();
        $ranges->refreshIfDue();

        self::assertSame(['verified' => true, 'vendor' => 'chatgpt'], $ranges->verify('::ffff:20.171.206.10'));
        self::assertSame(['verified' => false, 'vendor' => null], $ranges->verify('::ffff:20.171.207.1'));
    }

    public function testAListThatCannotBeWrittenIsReportedNotSilentlyDropped(): void
    {
        $this->transport->willReturnRanges(RangesFetch::http(200, self::LIST));
        chmod($this->dir, 0o500);
        try {
            $ranges = $this->ranges();
            self::assertTrue($ranges->refreshIfDue());
            self::assertNull($ranges->version());
            self::assertStringContainsString('could not be written', $ranges->lastError());
        } finally {
            chmod($this->dir, 0o700);
        }
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

    /**
     * One fetch, two files: the ranges for site capture, the referrer list for
     * the page-view counter, each read on its own path.
     */
    public function testA200WritesTheAiReferrerListBesideTheRanges(): void
    {
        $this->transport->willReturnRanges(RangesFetch::http(200, self::LIST_WITH_REFERRERS));

        self::assertTrue($this->ranges()->refreshIfDue());

        self::assertFileExists($this->dir.'/'.VendorRanges::FILE);
        self::assertFileExists($this->dir.'/'.AiReferrers::FILE);
        self::assertStringNotContainsString('chatgpt.com', (string) file_get_contents($this->dir.'/'.VendorRanges::FILE), 'the list is not kept twice');
        $referrers = $this->referrers();
        self::assertSame('3f2a9c1e0b7d', $referrers->version(), 'the document\'s version');
        self::assertSame($this->clock->now()->getTimestamp(), $referrers->fetchedAt());
        self::assertSame('claude', $referrers->match('claude.ai', ''));
        self::assertSame('perplexity', $referrers->match('', 'perplexity'));
        self::assertSame('none', $referrers->match('google.fr', 'chatgpt.com'), 'searchHosts stored with the list');
        self::assertSame('', $this->ranges()->lastError());
    }

    /**
     * A backend from before the list joined the document: the list is stored
     * empty, so the next check stays conditional and the counter counts every
     * view as ``none``.
     */
    public function testA200WithoutAiReferrersStoresEmptyLists(): void
    {
        $this->transport->willReturnRanges(RangesFetch::http(200, self::LIST));

        $this->ranges()->refreshIfDue();

        $referrers = $this->referrers();
        self::assertSame('3f2a9c1e0b7d', $referrers->version());
        self::assertSame(0, $referrers->hostCount());
        self::assertSame(0, $referrers->utmSourceCount());
        self::assertSame('none', $referrers->match('chatgpt.com', ''));
    }

    public function testA304RestampsBothFiles(): void
    {
        $this->transport->willReturnRanges(RangesFetch::http(200, self::LIST_WITH_REFERRERS));
        $this->ranges()->refreshIfDue();

        $this->clock->modify('+20 days');
        self::assertTrue($this->referrers()->isStale());
        $this->transport->willReturnRanges(RangesFetch::http(304));
        $this->ranges()->refreshIfDue();

        self::assertSame('3f2a9c1e0b7d', $this->transport->rangeRequests[1]['version']);
        self::assertSame('2026-11-04T10:00:00Z', $this->ranges()->fetchedAtIso());
        self::assertSame($this->clock->now()->getTimestamp(), $this->referrers()->fetchedAt());
        self::assertSame('claude', $this->referrers()->match('claude.ai', ''), 'confirmed fresh, still matching');
    }

    /**
     * The upgrade from 0.8.x: vendor-ranges.json is there, ai-referrers.json is
     * not, and the backend's version may well not have moved. A conditional
     * fetch would answer 304 for as long as it does not, and the list would
     * never arrive.
     */
    public function testAMissingAiReferrerListDropsIfNoneMatch(): void
    {
        $this->transport->willReturnRanges(RangesFetch::http(200, self::LIST_WITH_REFERRERS));
        $this->ranges(withReferrers: false)->refreshIfDue();
        self::assertFileDoesNotExist($this->dir.'/'.AiReferrers::FILE, '0.8.x writes the ranges only');

        $this->clock->modify('+1 day');
        $this->transport->willReturnRanges(RangesFetch::http(200, self::LIST_WITH_REFERRERS));
        $this->ranges()->refreshIfDue();

        self::assertSame('', $this->transport->rangeRequests[1]['version'], 'unconditional');
        self::assertSame('claude', $this->referrers()->match('claude.ai', ''));

        $this->clock->modify('+1 day');
        $this->transport->willReturnRanges(RangesFetch::http(304));
        $this->ranges()->refreshIfDue();
        self::assertSame('3f2a9c1e0b7d', $this->transport->rangeRequests[2]['version'], 'conditional again once both are stored');
    }

    /**
     * The upgrade from 0.10.x: its list may already be stored at the ranges
     * version that introduced ``searchHosts``, without the key. A conditional
     * fetch would answer 304 until the backend's version next moved.
     */
    public function testAnAiReferrerListWithoutSearchHostsDropsIfNoneMatch(): void
    {
        $this->transport->willReturnRanges(RangesFetch::http(200, self::LIST_WITH_REFERRERS));
        $this->ranges()->refreshIfDue();
        $file = $this->dir.'/'.AiReferrers::FILE;
        $legacy = (array) json_decode((string) file_get_contents($file), true);
        unset($legacy['searchHosts']);
        file_put_contents($file, json_encode($legacy));
        self::assertSame('chatgpt', $this->referrers()->match('google.fr', 'chatgpt.com'), 'as 0.10.x stored it');

        $this->clock->modify('+1 day');
        $this->transport->willReturnRanges(RangesFetch::http(200, self::LIST_WITH_REFERRERS));
        $this->ranges()->refreshIfDue();

        self::assertSame('', $this->transport->rangeRequests[1]['version'], 'unconditional');
        self::assertSame('none', $this->referrers()->match('google.fr', 'chatgpt.com'), 'the 200 brought searchHosts');

        $this->clock->modify('+1 day');
        $this->transport->willReturnRanges(RangesFetch::http(304));
        $this->ranges()->refreshIfDue();
        self::assertSame('3f2a9c1e0b7d', $this->transport->rangeRequests[2]['version'], 'conditional again once the key is stored');
    }

    /**
     * Two files are two writes: one can land without the other. A version
     * mismatch means they no longer describe one document.
     */
    public function testAnAiReferrerListFromAnotherVersionDropsIfNoneMatch(): void
    {
        $this->transport->willReturnRanges(RangesFetch::http(200, self::LIST_WITH_REFERRERS));
        $this->ranges()->refreshIfDue();
        $this->referrers()->store('000000000000', [], []);

        $this->clock->modify('+1 day');
        $this->transport->willReturnRanges(RangesFetch::http(200, self::LIST_WITH_REFERRERS));
        $this->ranges()->refreshIfDue();

        self::assertSame('', $this->transport->rangeRequests[1]['version']);
        self::assertSame('3f2a9c1e0b7d', $this->referrers()->version());
    }

    /**
     * A 304 to the unconditional request sent while the list is missing (a
     * cache in between answering for the backend) confirms the ranges and has
     * no list to restamp: that is not a write failure.
     */
    public function testA304WithNoAiReferrerListStoredIsNotAnError(): void
    {
        $this->transport->willReturnRanges(RangesFetch::http(200, self::LIST));
        $this->ranges(withReferrers: false)->refreshIfDue();

        $this->clock->modify('+1 day');
        $this->transport->willReturnRanges(RangesFetch::http(304));
        $ranges = $this->ranges();
        $ranges->refreshIfDue();

        self::assertSame('', $this->transport->rangeRequests[1]['version']);
        self::assertSame('', $ranges->lastError());
        self::assertSame('2026-10-16T10:00:00Z', $ranges->fetchedAtIso());
        self::assertNull($this->referrers()->version());
    }

    public function testAFailureKeepsThePreviousAiReferrerList(): void
    {
        $this->transport->willReturnRanges(RangesFetch::http(200, self::LIST_WITH_REFERRERS));
        $this->ranges()->refreshIfDue();

        $this->clock->modify('+1 day');
        $this->transport->willReturnRanges(RangesFetch::http(500));
        $this->ranges()->refreshIfDue();

        self::assertSame('2026-10-15T10:00:00Z', gmdate('Y-m-d\TH:i:s\Z', (int) $this->referrers()->fetchedAt()));
        self::assertSame('claude', $this->referrers()->match('claude.ai', ''));
    }

    private function ranges(bool $withReferrers = true): VendorRanges
    {
        return new VendorRanges(
            new BufferDirectory($this->dir),
            $this->transport,
            new AnalyticsOptions(true, self::ENDPOINT, self::TOKEN, true, false),
            $this->state,
            $this->clock,
            $withReferrers ? $this->referrers() : null,
        );
    }

    private function referrers(): AiReferrers
    {
        return new AiReferrers(new BufferDirectory($this->dir), $this->clock);
    }
}
