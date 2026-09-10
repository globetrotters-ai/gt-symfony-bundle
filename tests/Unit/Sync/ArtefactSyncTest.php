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
use Psr\Cache\CacheItemInterface;
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

    /**
     * A key shaped like the ones the presence stack issues (32 hex chars).
     */
    private const INDEXNOW_KEY = 'e715a2e7bf3c4a1d8e0b6f9c2d5a7e14';

    private ArrayAdapter $pool;
    private Options $options;
    private ArtefactCache $cache;
    private FakeFetcher $fetcher;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
        $this->options = new Options($this->pool, self::BASE_URL, 'daily', '/');
        $this->cache = new ArtefactCache($this->pool, self::BASE_URL);
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

    /**
     * A repointed install drops the previous source's bundle and everything
     * learned from it before pulling, so a pull that then fails leaves nothing
     * of the old presence behind: stale-serve is for the source this install
     * points at.
     */
    public function testAForeignBundleIsForgottenBeforePulling(): void
    {
        $previous = new ArtefactCache($this->pool, 'https://lyon.globetrotters.ai');
        $previous->store(['llms.txt' => 'lyon'], 'lyon-v1', 1000);
        $this->options->updateState([
            'installed_version' => 'lyon-v1',
            'content_hash' => 'lyon-hash',
            'indexnow_key' => self::INDEXNOW_KEY,
            'last_refresh' => 1000,
            'content_changed_at' => 1000,
        ]);
        $this->fetcher->fallback(FetchResult::http(503, ''));

        $result = $this->sync()->run();

        self::assertFalse($result->isSuccess());
        self::assertFalse($this->cache->holdsForeignBundle());
        $previous->reset();
        self::assertFalse($previous->hasAny(), 'the previous source\'s bundle must be gone, not merely unserved');
        $state = $this->options->state();
        self::assertSame('', $state['installed_version']);
        self::assertSame('', $state['content_hash']);
        self::assertSame('', $this->options->indexNowKey());
        self::assertSame(0, $state['last_refresh']);
        self::assertSame(0, $state['content_changed_at']);
        self::assertNotSame('', $state['last_error']);
    }

    public function testTheNewSourceIsPulledAndServedAfterForgetting(): void
    {
        (new ArtefactCache($this->pool, 'https://lyon.globetrotters.ai'))->store(['llms.txt' => 'lyon'], 'lyon-v1', 1000);
        $this->serveRequiredFiles();

        self::assertTrue($this->sync()->run()->isSuccess());
        self::assertSame('hello', $this->cache->get('llms.txt'));
    }

    public function testTheCurrentSourcesBundleIsNeverForgotten(): void
    {
        $this->cache->store(['llms.txt' => 'hello'], 'v1', 1000);
        $this->options->updateState(['installed_version' => 'v1']);

        self::assertFalse($this->sync()->forgetForeignBundle());
        self::assertSame('hello', $this->cache->get('llms.txt'));
        self::assertSame('v1', $this->options->state()['installed_version']);
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

    public function testCachePersistenceFailureIsReportedAndKeepsStaleBundle(): void
    {
        $pool = new class extends ArrayAdapter {
            public bool $failManifest = false;

            public function save(CacheItemInterface $item): bool
            {
                if ($this->failManifest && ArtefactCache::ITEM === $item->getKey()) {
                    return false;
                }

                return parent::save($item);
            }
        };
        $this->pool = $pool;
        $this->options = new Options($pool, self::BASE_URL, 'daily', '/');
        $this->cache = new ArtefactCache($pool, self::BASE_URL);
        $this->serveRequiredFiles();
        self::assertTrue($this->sync()->run()->isSuccess());

        $pool->failManifest = true;
        $this->fetcher->on('/llms.txt', FetchResult::http(200, 'new content'));
        $result = $this->sync()->run();

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('persist', $result->errorMessage());
        self::assertSame('hello', $this->cache->get('llms.txt'));
        self::assertSame('hello', (new ArtefactCache($pool, self::BASE_URL))->get('llms.txt'));
    }

    public function testCachePersistenceExceptionIsSurfacedInTheError(): void
    {
        $pool = new class extends ArrayAdapter {
            public bool $throwOnManifest = false;

            public function save(CacheItemInterface $item): bool
            {
                if ($this->throwOnManifest && ArtefactCache::ITEM === $item->getKey()) {
                    throw new \RuntimeException('Redis server went away');
                }

                return parent::save($item);
            }
        };
        $this->pool = $pool;
        $this->options = new Options($pool, self::BASE_URL, 'daily', '/');
        $this->cache = new ArtefactCache($pool, self::BASE_URL);
        $this->serveRequiredFiles();
        self::assertTrue($this->sync()->run()->isSuccess());

        $pool->throwOnManifest = true;
        $this->fetcher->on('/llms.txt', FetchResult::http(200, 'new content'));
        $result = $this->sync()->run();

        // A backend that blew up must be diagnosable, not just "failed".
        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('Redis server went away', $result->errorMessage());
        self::assertStringContainsString('Redis server went away', (string) $this->options->state()['last_error']);
        self::assertSame('hello', $this->cache->get('llms.txt'));
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

    /**
     * `content_changed_at` is what the generated sitemap reports as
     * `<lastmod>`, so it must move only when the content does — a daily
     * refresh that changed nothing must not restamp every URL as fresh.
     */
    public function testContentChangedAtOnlyMovesWhenTheContentDoes(): void
    {
        $this->serveRequiredFiles();
        $this->sync()->run();
        $firstPull = $this->clock->now()->getTimestamp();

        self::assertSame($firstPull, $this->options->state()['content_changed_at']);

        $this->clock->modify('+1 day');
        $this->sync()->run();

        self::assertSame($firstPull, $this->options->state()['content_changed_at']);
        self::assertSame($this->clock->now()->getTimestamp(), $this->options->state()['last_refresh']);

        $this->clock->modify('+1 day');
        $this->fetcher->on('/llms.txt', FetchResult::http(200, 'republished'));
        $this->sync()->run();

        self::assertSame($this->clock->now()->getTimestamp(), $this->options->state()['content_changed_at']);
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

    public function testUpstreamMarkerKeyIsStoredInState(): void
    {
        $this->serveRequiredFiles();
        $this->fetcher->on('/'.ContentTypes::VERSION_MARKER, $this->markerWithKey(self::INDEXNOW_KEY));

        $this->sync()->run();

        self::assertSame(self::INDEXNOW_KEY, $this->options->state()['indexnow_key']);
    }

    public function testAMarkerWithoutTheKeyClearsAPreviouslyStoredOne(): void
    {
        $this->serveRequiredFiles();
        $this->fetcher->on('/'.ContentTypes::VERSION_MARKER, $this->markerWithKey(self::INDEXNOW_KEY));
        $this->sync()->run();

        // A key removed upstream (rotation to nothing, or a destination moved to
        // a keyless environment) must stop being served here, not linger.
        $this->fetcher->on('/'.ContentTypes::VERSION_MARKER, FetchResult::http(200, '{"version":"2026-06-02-120000"}'));
        $this->sync()->run();

        self::assertSame('', $this->options->state()['indexnow_key']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableMarkerKeys')]
    public function testAnUnusableMarkerKeyIsNotStored(mixed $value): void
    {
        $this->serveRequiredFiles();
        $this->fetcher->on(
            '/'.ContentTypes::VERSION_MARKER,
            FetchResult::http(200, (string) json_encode(['version' => 'v9', 'indexnowKey' => $value])),
        );

        $this->sync()->run();

        self::assertSame('', $this->options->state()['indexnow_key']);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function unusableMarkerKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => ['abc'];
        yield 'newline' => ["abcdefgh\n"];
        yield 'not text' => [42];
        yield 'array' => [['abcdefgh']];
    }

    public function testSynthesizedMarkerLeavesNoKey(): void
    {
        // Nothing upstream serves a marker, so there is no key to learn. The
        // environment is keyless and the route must stay unserved.
        $this->serveRequiredFiles();

        $this->sync()->run();

        self::assertSame('', $this->options->state()['indexnow_key']);
    }

    public function testAKeylessSyncStillSucceeds(): void
    {
        // The key is never a required fetched path: adding it to requiredPaths()
        // would fail every sync in an environment where no key is configured.
        $this->serveRequiredFiles();

        $result = $this->sync()->run();

        self::assertTrue($result->isSuccess());
        self::assertSame('', $this->options->state()['last_error']);
        self::assertSame('hello', $this->cache->get('llms.txt'));
    }

    public function testTheKeyStaysOutOfTheContentHash(): void
    {
        $this->serveRequiredFiles();
        $this->sync()->run();
        $keylessHash = $this->options->state()['content_hash'];

        $this->fetcher->on('/'.ContentTypes::VERSION_MARKER, $this->markerWithKey(self::INDEXNOW_KEY));
        $this->sync()->run();

        // The key rides on the marker, which is added after hashing — so drift
        // detection cannot fire on a key appearing, disappearing or rotating.
        self::assertSame(self::EXPECTED_HASH, $keylessHash);
        self::assertSame($keylessHash, $this->options->state()['content_hash']);
    }

    public function testAFailedSyncKeepsTheLastKnownKey(): void
    {
        $this->serveRequiredFiles();
        $this->fetcher->on('/'.ContentTypes::VERSION_MARKER, $this->markerWithKey(self::INDEXNOW_KEY));
        $this->sync()->run();

        // Same rule as the bundle itself: a failed pull learns nothing, so the
        // site keeps serving the last key it was told about.
        $this->fetcher->on('/llms.txt', FetchResult::http(500, ''));
        $this->sync()->run();

        self::assertSame(self::INDEXNOW_KEY, $this->options->state()['indexnow_key']);
    }

    /**
     * An upstream marker carrying an IndexNow key, shaped as the backend
     * renders it (``render_version_marker``).
     */
    private function markerWithKey(string $key): FetchResult
    {
        return FetchResult::http(200, (string) json_encode([
            'version' => '2026-06-01-120000',
            'contentHash' => 'abc',
            'indexnowKey' => $key,
        ]));
    }

    /**
     * A proxy or maintenance mode answering 200 with an HTML page passes every
     * status and size check. It must not replace a good bundle, and nothing the
     * failed pull saw may leak into state: not the change date the sitemap
     * reports, and not the IndexNow key.
     */
    public function testAnHtmlPageServedAsJsonKeepsTheWholeLastGoodGeneration(): void
    {
        $this->serveRequiredFiles();
        $this->fetcher->on('/'.ContentTypes::VERSION_MARKER, $this->markerWithKey(self::INDEXNOW_KEY));
        self::assertTrue($this->sync()->run()->isSuccess());
        $before = $this->options->state();

        $this->clock->modify('+1 day');
        $this->fetcher->on('/schema.json', FetchResult::http(200, '<!DOCTYPE html><html><body>Down for maintenance</body></html>'));
        $this->fetcher->on('/llms.txt', FetchResult::http(200, 'republished'));
        $this->fetcher->on('/'.ContentTypes::VERSION_MARKER, $this->markerWithKey('rotated-key-0000000000000000'));
        $result = $this->sync()->run();

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('/schema.json', $result->errorMessage());
        self::assertStringContainsString('not valid JSON', $result->errorMessage());
        // Every file of the previous generation, not only the rejected one.
        foreach (self::BODIES as $path => $body) {
            self::assertSame($body, $this->cache->get($path));
        }
        $after = $this->options->state();
        foreach (['content_hash', 'content_changed_at', 'indexnow_key', 'installed_version', 'last_refresh'] as $key) {
            self::assertSame($before[$key], $after[$key], $key.' must not advance on a rejected pull');
        }
        self::assertSame($result->errorMessage(), $after['last_error']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableJsonArtefacts')]
    public function testAnUnusableJsonArtefactIsRejected(string $path, string $body): void
    {
        $this->serveRequiredFiles();
        self::assertTrue($this->sync()->run()->isSuccess());

        $this->fetcher->on('/'.$path, FetchResult::http(200, $body));
        $result = $this->sync()->run();

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('/'.$path, $result->errorMessage());
        self::assertSame(self::BODIES[$path], $this->cache->get($path));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function unusableJsonArtefacts(): iterable
    {
        yield 'truncated' => ['ai.json', '{"a":'];
        yield 'empty' => ['.well-known/mcp.json', ''];
        yield 'json null' => ['ai.json', 'null'];
        yield 'bare string' => ['.well-known/agent-card.json', '"maintenance"'];
        yield 'list where an object belongs' => ['ai.json', '[{"a":1}]'];
        yield 'schema scalar' => ['schema.json', '42'];
        yield 'schema list of scalars' => ['schema.json', '[1,2]'];
        yield 'schema empty list' => ['schema.json', '[]'];
    }

    /**
     * JSON-LD allows a top-level array of node objects, so schema.json is held
     * to that rather than to a single object.
     */
    public function testSchemaMayBeAListOfNodes(): void
    {
        $this->serveRequiredFiles();
        $this->fetcher->on('/schema.json', FetchResult::http(200, '[{"@type":"TouristDestination"},{"@type":"Place"}]'));

        self::assertTrue($this->sync()->run()->isSuccess());
    }

    public function testTextArtefactsAreNotHeldToJson(): void
    {
        $this->serveRequiredFiles();
        $this->fetcher->on('/llms.txt', FetchResult::http(200, "# Nantes\n\n> {not json} <b>markdown may carry markup</b>\n"));

        self::assertTrue($this->sync()->run()->isSuccess());
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
