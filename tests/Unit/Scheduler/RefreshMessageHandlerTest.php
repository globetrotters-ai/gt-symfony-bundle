<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Scheduler;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\AnalyticsState;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\RangesFetch;
use Globetrotters\AiPresenceBundle\Analytics\VendorRanges;
use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Client\FetchResult;
use Globetrotters\AiPresenceBundle\Scheduler\RefreshMessage;
use Globetrotters\AiPresenceBundle\Scheduler\RefreshMessageHandler;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Globetrotters\AiPresenceBundle\Sync\ArtefactSync;
use Globetrotters\AiPresenceBundle\Sync\RefreshLock;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeFetcher;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeIngestTransport;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use Globetrotters\AiPresenceBundle\Tests\Unit\Analytics\VendorRangesTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * The schedule polls hourly; this handler is what keeps the scheduler lane on
 * refresh_interval, the same due-check gt:refresh applies under an hourly cron.
 */
final class RefreshMessageHandlerTest extends TestCase
{
    private const BASE_URL = 'https://acme.globetrotters.ai';

    private const BODIES = [
        'llms.txt' => 'hello',
        'ai.json' => '{"a":1}',
        'schema.json' => '{"@context":"https://schema.org"}',
        '.well-known/mcp.json' => '{"m":1}',
        '.well-known/agent-card.json' => '{"c":2}',
        '.well-known/ai-catalog.json' => '{"specVersion":"1.0"}',
        '.well-known/agent-skills/index.json' => '{"skills":[]}',
        'agent.json' => '{"name":"Acme"}',
        'AGENTS.md' => "# Acme\n",
    ];

    private ArrayAdapter $pool;
    private MockClock $clock;
    private FakeFetcher $fetcher;
    private RefreshMessageHandler $handler;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
        $this->clock = new MockClock('2026-09-10 10:00:00', 'UTC');
        $this->fetcher = $this->upstream();
        $this->handler = $this->handler('daily');
    }

    public function testAnEmptyPoolIsFilledOnTheFirstPoll(): void
    {
        ($this->handler)(new RefreshMessage());

        self::assertSame('hello', $this->cache()->get('llms.txt'));
    }

    public function testPollsWithinTheIntervalFetchNothing(): void
    {
        ($this->handler)(new RefreshMessage());
        $fetched = \count($this->fetcher->requested);

        $this->clock->sleep(23 * 3600);
        ($this->handler)(new RefreshMessage());

        self::assertCount($fetched, $this->fetcher->requested);
    }

    public function testThePollAfterTheIntervalRefreshes(): void
    {
        ($this->handler)(new RefreshMessage());
        $fetched = \count($this->fetcher->requested);

        $this->clock->sleep(86400);
        ($this->handler)(new RefreshMessage());

        self::assertGreaterThan($fetched, \count($this->fetcher->requested));
    }

    public function testTheWeeklyIntervalIsHonoured(): void
    {
        $handler = $this->handler('weekly');
        $handler(new RefreshMessage());
        $fetched = \count($this->fetcher->requested);

        $this->clock->sleep(6 * 86400);
        $handler(new RefreshMessage());

        self::assertCount($fetched, $this->fetcher->requested);
    }

    /**
     * A failed pull leaves last_refresh alone, so the next hourly poll retries
     * rather than waiting out the interval with the bundle missing.
     */
    public function testAFailedPullIsRetriedOnTheNextPoll(): void
    {
        $this->fetcher = (new FakeFetcher())->fallback(FetchResult::http(503, ''));
        $this->handler = $this->handler('daily');
        ($this->handler)(new RefreshMessage());
        self::assertNull($this->cache()->get('llms.txt'));

        $this->fetcher = $this->upstream();
        $this->handler = $this->handler('daily');
        $this->clock->sleep(3600);
        ($this->handler)(new RefreshMessage());

        self::assertSame('hello', $this->cache()->get('llms.txt'));
    }

    /**
     * A cache:clear empties the bundle and its state together; the next poll
     * must see a refresh as due, not wait for the old last_refresh to age.
     */
    public function testAPoolClearedMidIntervalIsRefilledOnTheNextPoll(): void
    {
        ($this->handler)(new RefreshMessage());
        $this->pool->clear();

        // A worker resets services between messages (kernel.reset), dropping
        // the state Options memoised from the first poll.
        $this->handler = $this->handler('daily');
        $this->clock->sleep(3600);
        ($this->handler)(new RefreshMessage());

        self::assertSame('hello', $this->cache()->get('llms.txt'));
    }

    /**
     * The vendor ranges ride the hourly poll on their own daily due-check,
     * whether or not the artefact refresh is due or succeeds.
     */
    public function testTheVendorRangesAreCheckedDailyIndependentlyOfTheSync(): void
    {
        $dir = TempDirectory::make();
        try {
            $this->fetcher = (new FakeFetcher())->fallback(FetchResult::http(503, ''));
            $transport = (new FakeIngestTransport())->willReturnRanges(RangesFetch::http(200, VendorRangesTest::LIST));
            $options = new Options($this->pool, self::BASE_URL, 'daily', '/');
            $ranges = new VendorRanges(
                new BufferDirectory($dir),
                $transport,
                new AnalyticsOptions(true, VendorRangesTest::ENDPOINT, VendorRangesTest::TOKEN, true, false),
                new AnalyticsState($this->pool),
                $this->clock,
            );
            $handler = new RefreshMessageHandler(new ArtefactSync($this->fetcher, new ArtefactCache($this->pool, self::BASE_URL), $options, $this->clock), $ranges);

            $handler(new RefreshMessage());
            self::assertCount(1, $transport->rangeRequests);
            self::assertSame('3f2a9c1e0b7d', $ranges->version(), 'fetched though the artefact pull failed');

            $this->clock->sleep(3600);
            $handler(new RefreshMessage());
            self::assertCount(1, $transport->rangeRequests, 'at most once a day');

            $this->clock->sleep(86400);
            $transport->willReturnRanges(RangesFetch::http(304));
            $handler(new RefreshMessage());
            self::assertCount(2, $transport->rangeRequests);
        } finally {
            TempDirectory::remove($dir);
        }
    }

    private function upstream(): FakeFetcher
    {
        $fetcher = (new FakeFetcher())->fallback(FetchResult::http(404, ''));
        foreach (self::BODIES as $path => $body) {
            $fetcher->on('/'.$path, FetchResult::http(200, $body));
        }

        return $fetcher;
    }

    public function testAPollSkipsWhileACronRefreshHoldsTheLock(): void
    {
        $dir = TempDirectory::make();
        try {
            $lock = new RefreshLock(new BufferDirectory($dir));
            $options = new Options($this->pool, self::BASE_URL, 'daily', '/');
            $handler = new RefreshMessageHandler(new ArtefactSync($this->fetcher, $this->cache(), $options, $this->clock), null, $lock);

            $lock->withLock(static fn () => $handler(new RefreshMessage()));
            self::assertSame([], $this->fetcher->requested);
            self::assertFalse($this->cache()->hasAny());

            // The next poll, with the lock free, fills the empty pool.
            $handler(new RefreshMessage());
            self::assertTrue($this->cache()->hasAny());
        } finally {
            TempDirectory::remove($dir);
        }
    }

    private function handler(string $interval): RefreshMessageHandler
    {
        $options = new Options($this->pool, self::BASE_URL, $interval, '/');

        return new RefreshMessageHandler(new ArtefactSync($this->fetcher, new ArtefactCache($this->pool, self::BASE_URL), $options, $this->clock));
    }

    private function cache(): ArtefactCache
    {
        return new ArtefactCache($this->pool, self::BASE_URL);
    }
}
