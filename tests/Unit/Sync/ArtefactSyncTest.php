<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Sync;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Client\FetcherInterface;
use Globetrotters\AiPresenceBundle\Client\FetchResult;
use Globetrotters\AiPresenceBundle\Serving\ContentTypes;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Globetrotters\AiPresenceBundle\Sync\ArtefactSync;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class ArtefactSyncTest extends TestCase
{
    private const BASE_URL = 'https://nantes.globetrotters.ai';

    private const BODIES = [
        'llms.txt' => 'hello',
        'ai.json' => '{"a":1}',
        'schema.json' => '{"@context":"https://schema.org"}',
        '.well-known/mcp.json' => '{"m":1}',
        '.well-known/agent-card.json' => '{"c":2}',
    ];

    /**
     * Computed with the backend canonical_hash implementation (hashlib, raw
     * inner digests) over BODIES — asserts byte-parity with the backend.
     */
    private const EXPECTED_HASH = '757cb0a99fe4f51d6dde4584377408d7c7e383461fd58aef676733636d845fde';

    private ArrayAdapter $pool;
    private Options $options;
    private ArtefactCache $cache;
    private FakeFetcher $fetcher;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
        $this->options = new Options($this->pool, self::BASE_URL, 'daily', '/');
        $this->cache = new ArtefactCache($this->pool);
        $this->fetcher = new FakeFetcher();
        $this->clock = new MockClock('2026-07-23 10:00:00', 'UTC');
    }

    private function sync(): ArtefactSync
    {
        return new ArtefactSync($this->fetcher, $this->cache, $this->options, $this->clock);
    }

    private function serveRequiredFiles(): void
    {
        foreach (self::BODIES as $path => $body) {
            $this->fetcher->on('/'.$path, FetchResult::http(200, $body));
        }
    }

    public function testFullPullStoresBundleAndState(): void
    {
        $this->serveRequiredFiles();

        $result = $this->sync()->run();

        self::assertTrue($result->isSuccess());
        self::assertTrue($result->hasChanged());
        self::assertSame([], $result->errors());
        foreach (self::BODIES as $path => $body) {
            self::assertSame($body, $this->cache->get($path));
        }

        $state = $this->options->state();
        self::assertSame(self::EXPECTED_HASH, $state['content_hash']);
        self::assertSame($state['installed_version'], $state['latest_version']);
        self::assertSame('', $state['last_error']);
        self::assertSame($this->clock->now()->getTimestamp(), $state['last_refresh']);
    }

    public function testContentHashMatchesBackendAlgorithm(): void
    {
        $this->serveRequiredFiles();

        $this->sync()->run();

        self::assertSame(self::EXPECTED_HASH, $this->options->state()['content_hash']);
    }

    public function testUpstreamMarkerUsedVerbatim(): void
    {
        $this->serveRequiredFiles();
        $markerBody = '{"generator":"globetrotters-apex","version":"2026-07-01-120000","contentHash":"abc"}';
        $this->fetcher->on('/'.ContentTypes::VERSION_MARKER, FetchResult::http(200, $markerBody));

        $result = $this->sync()->run();

        self::assertTrue($result->isSuccess());
        self::assertSame('2026-07-01-120000', $result->version());
        self::assertSame($markerBody, $this->cache->get(ContentTypes::VERSION_MARKER));
        self::assertSame('2026-07-01-120000', $this->cache->version());
    }

    public function testMarkerSynthesizedWhenUpstreamMissing(): void
    {
        $this->serveRequiredFiles();

        $result = $this->sync()->run();

        self::assertTrue($result->isSuccess());
        self::assertSame('2026-07-23-100000', $result->version());

        $marker = json_decode((string) $this->cache->get(ContentTypes::VERSION_MARKER), true);
        self::assertIsArray($marker);
        self::assertSame('globetrotters-apex-symfony-bundle', $marker['generator']);
        self::assertSame('nantes', $marker['destinationSlug']);
        self::assertSame('2026-07-23-100000', $marker['version']);
        self::assertSame(self::EXPECTED_HASH, $marker['contentHash']);
        self::assertSame('synthesized', $marker['source']);
        self::assertSame('2026-07-23T10:00:00+00:00', $marker['builtAt']);
    }

    public function testMarkerSynthesizedWhenUpstreamInvalidJson(): void
    {
        $this->serveRequiredFiles();
        $this->fetcher->on('/'.ContentTypes::VERSION_MARKER, FetchResult::http(200, 'not json'));

        $result = $this->sync()->run();

        self::assertSame('2026-07-23-100000', $result->version());
    }

    public function testAllOrNothingAbortKeepsStaleBundle(): void
    {
        $this->serveRequiredFiles();
        $this->sync()->run();

        // Second pull: one required file starts failing.
        $this->fetcher->on('/ai.json', FetchResult::http(500, ''));
        $result = $this->sync()->run();

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('/ai.json', $result->errorMessage());
        self::assertStringContainsString('500', $result->errorMessage());
        // Stale bundle untouched.
        self::assertSame('{"a":1}', $this->cache->get('ai.json'));
        self::assertSame($result->errorMessage(), $this->options->state()['last_error']);
    }

    public function testTransportErrorAborts(): void
    {
        $this->serveRequiredFiles();
        $this->fetcher->on('/llms.txt', FetchResult::error('Connection refused'));

        $result = $this->sync()->run();

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('Connection refused', $result->errorMessage());
        self::assertFalse($this->cache->hasAny());
    }

    public function testOversizeBodyAborts(): void
    {
        $this->serveRequiredFiles();
        $this->fetcher->on('/llms.txt', FetchResult::http(200, str_repeat('x', FetcherInterface::MAX_BODY_BYTES + 1)));

        $result = $this->sync()->run();

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('size limit', $result->errorMessage());
    }

    public function testBodyExactlyAtLimitAccepted(): void
    {
        $this->serveRequiredFiles();
        $this->fetcher->on('/llms.txt', FetchResult::http(200, str_repeat('x', FetcherInterface::MAX_BODY_BYTES)));

        self::assertTrue($this->sync()->run()->isSuccess());
    }

    public function testEmpty200BodyAccepted(): void
    {
        $this->serveRequiredFiles();
        $this->fetcher->on('/llms.txt', FetchResult::http(200, ''));

        $result = $this->sync()->run();

        self::assertTrue($result->isSuccess());
        self::assertSame('', $this->cache->get('llms.txt'));
    }

    public function testAllEmptyBodiesAbortKeepingStaleBundle(): void
    {
        $this->serveRequiredFiles();
        $this->sync()->run();

        // A broken origin/proxy answers every required file with 200 + no body:
        // the pull must be refused so the good bundle keeps serving.
        foreach (array_keys(self::BODIES) as $path) {
            $this->fetcher->on('/'.$path, FetchResult::http(200, ''));
        }
        $result = $this->sync()->run();

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('empty', $result->errorMessage());
        self::assertSame('hello', $this->cache->get('llms.txt'));
    }

    public function testIdenticalRepullReportsUnchanged(): void
    {
        $this->serveRequiredFiles();

        self::assertTrue($this->sync()->run()->hasChanged());

        // Same content, later clock: a synthesized version would differ, but
        // change detection keys off the content hash.
        $this->clock->modify('+1 day');
        $second = $this->sync()->run();

        self::assertTrue($second->isSuccess());
        self::assertFalse($second->hasChanged());
    }

    public function testChangedContentReportsChanged(): void
    {
        $this->serveRequiredFiles();
        $this->sync()->run();

        $this->fetcher->on('/llms.txt', FetchResult::http(200, 'republished'));
        $result = $this->sync()->run();

        self::assertTrue($result->isSuccess());
        self::assertTrue($result->hasChanged());
    }

    public function testNotConnectedFails(): void
    {
        $this->options = new Options($this->pool, '', 'daily', '/');
        $this->serveRequiredFiles();

        $result = $this->sync()->run();

        self::assertFalse($result->isSuccess());
        self::assertSame(['No destination is connected yet.'], $result->errors());
        self::assertSame([], $this->fetcher->requested);
    }

    public function testCheckLatestReadsUpstreamMarkerWithoutPulling(): void
    {
        $this->fetcher->on('/'.ContentTypes::VERSION_MARKER, FetchResult::http(200, '{"version":"2026-08-01-000000"}'));

        self::assertSame('2026-08-01-000000', $this->sync()->checkLatest());
        self::assertCount(1, $this->fetcher->requested);
        self::assertFalse($this->cache->hasAny());
    }

    public function testCheckLatestReturnsEmptyOnMissingMarker(): void
    {
        self::assertSame('', $this->sync()->checkLatest());
    }

    public function testCheckLatestReturnsEmptyWhenNotConnected(): void
    {
        $this->options = new Options($this->pool, '', 'daily', '/');

        self::assertSame('', $this->sync()->checkLatest());
        self::assertSame([], $this->fetcher->requested);
    }
}
