<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\AiReferrers;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\PageViewRules;
use Globetrotters\AiPresenceBundle\Tests\Support\OpenRecordingStream;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class AiReferrersTest extends TestCase
{
    /** The aiReferrers block of the ranges document, as gt-backend serves it. */
    public const HOSTS = [
        'chatgpt.com' => 'chatgpt',
        'chat.openai.com' => 'chatgpt',
        'claude.ai' => 'claude',
        'www.perplexity.ai' => 'perplexity',
        'gemini.google.com' => 'gemini',
        'copilot.microsoft.com' => 'copilot',
    ];

    public const UTM_SOURCES = [
        'chatgpt.com' => 'chatgpt',
        'copilot.com' => 'copilot',
        'gemini' => 'gemini',
        'geminiforbusiness' => 'gemini',
        'perplexity' => 'perplexity',
        'perplexity.ai' => 'perplexity',
    ];

    public const SEARCH_HOSTS = [
        'google.*',
        'www.google.*',
        'bing.com',
        'www.bing.com',
        'duckduckgo.com',
        'www.duckduckgo.com',
    ];

    private string $dir;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
        $this->clock = new MockClock('2026-10-16 10:00:00', 'UTC');
    }

    protected function tearDown(): void
    {
        OpenRecordingStream::unregister();
        TempDirectory::remove($this->dir);
    }

    public function testNoListMatchesNothing(): void
    {
        $referrers = $this->referrers();

        self::assertSame(PageViewRules::PLATFORM_NONE, $referrers->match('chatgpt.com', 'chatgpt.com'));
        self::assertNull($referrers->version());
        self::assertNull($referrers->fetchedAt());
        self::assertTrue($referrers->isStale());
        self::assertSame(0, $referrers->hostCount());
        self::assertSame(0, $referrers->utmSourceCount());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function visits(): iterable
    {
        yield 'a referrer host' => ['chatgpt.com', '', 'chatgpt'];
        yield 'another host of the same platform' => ['chat.openai.com', '', 'chatgpt'];
        yield 'a utm_source alone' => ['', 'perplexity', 'perplexity'];
        yield 'an unknown host, a known utm_source' => ['news.example', 'chatgpt.com', 'chatgpt'];
        yield 'a known host wins over another utm_source' => ['claude.ai', 'perplexity', 'claude'];
        yield 'neither known' => ['news.example', 'newsletter', 'none'];
        yield 'a host is not looked up as a utm_source' => ['perplexity', '', 'none'];
        yield 'a utm_source is not looked up as a host' => ['', 'claude.ai', 'none'];
        yield 'a parent domain is not a match' => ['openai.com', '', 'none'];
        yield 'a subdomain is not a match' => ['eu.chatgpt.com', '', 'none'];
    }

    /**
     * The PRD's five probes: Copilot's suffixed tag, a ChatGPT tag carried
     * behind a search engine, and the tags that arrive with no referrer.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function referralSignals(): iterable
    {
        yield 'a suffixed copilot tag, no referrer' => ['', 'copilot.com?form=MG0AUO', 'copilot'];
        yield 'a chatgpt tag behind google' => ['www.google.fr', 'chatgpt.com', 'none'];
        yield 'a chatgpt tag, no referrer' => ['', 'chatgpt.com', 'chatgpt'];
        yield 'a gemini tag' => ['', 'gemini', 'gemini'];
        yield 'a chatgpt referrer and tag' => ['chatgpt.com', 'chatgpt.com', 'chatgpt'];
        yield 'a tag that is only a query' => ['', '?form=MG0AUO', 'none'];
        yield 'a cut tag is still matched exactly' => ['', 'copilot.co?form=x', 'none'];
        yield 'a chatgpt tag behind bing' => ['www.bing.com', 'chatgpt.com', 'none'];
        yield 'a chatgpt tag behind an unknown site' => ['news.example', 'chatgpt.com', 'chatgpt'];
        yield 'a gemini referrer is not a google search' => ['gemini.google.com', 'chatgpt.com', 'gemini'];
    }

    #[DataProvider('referralSignals')]
    public function testCatchesSuffixedTagsAndIgnoresTagsBehindSearch(string $host, string $utmSource, string $platform): void
    {
        $this->store();

        self::assertSame($platform, $this->referrers()->match($host, $utmSource));
    }

    /**
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function searchHostPatterns(): iterable
    {
        yield 'name.* with one TLD' => [['google.*'], 'google.fr', true];
        yield 'name.* with two TLD labels' => [['google.*'], 'google.co.uk', true];
        yield 'name.* is not a subdomain' => [['google.*'], 'gemini.google.com', false];
        yield 'name.* is not a suffix of another name' => [['google.*'], 'notgoogle.com', false];
        yield 'name.* needs a TLD' => [['google.*'], 'google', false];
        yield 'name.* takes at most two labels' => [['google.*'], 'google.co.uk.example', false];
        yield 'name.* takes letters only' => [['google.*'], 'google.c0m', false];
        yield 'name.* takes two letters or more' => [['google.*'], 'google.c', false];
        yield 'www.name.*' => [['www.google.*'], 'www.google.de', true];
        yield 'a bare host matches exactly' => [['bing.com'], 'bing.com', true];
        yield 'a bare host is not a subdomain' => [['bing.com'], 'www.bing.com', false];
        yield 'a dot in a pattern is literal' => [['bing.com'], 'bingxcom', false];
        yield 'an empty list matches nothing' => [[], 'google.fr', false];
        yield 'an empty list does not match the empty host' => [[], '', false];
    }

    /**
     * @param list<string> $patterns
     */
    #[DataProvider('searchHostPatterns')]
    public function testSearchHostPatterns(array $patterns, string $host, bool $isSearch): void
    {
        $this->referrers()->store('3f2a9c1e0b7d', [], ['chatgpt.com' => 'chatgpt'], $patterns);

        self::assertSame($isSearch ? 'none' : 'chatgpt', $this->referrers()->match($host, 'chatgpt.com'));
    }

    /**
     * A ranges document from before ``searchHosts``: a tag behind google
     * still counts, as it did.
     */
    public function testWithoutSearchHostsATagBehindSearchStillCounts(): void
    {
        $referrers = AiReferrers::parse(['hosts' => self::HOSTS, 'utmSources' => self::UTM_SOURCES]);
        self::assertSame([], $referrers['searchHosts']);

        $this->referrers()->store('3f2a9c1e0b7d', $referrers['hosts'], $referrers['utmSources'], $referrers['searchHosts']);

        self::assertSame('chatgpt', $this->referrers()->match('www.google.fr', 'chatgpt.com'));
    }

    /**
     * A file written by 0.10.x has no ``searchHosts``: it still loads, as an
     * empty list, rather than being discarded until the next daily fetch.
     */
    public function testAFileWithoutSearchHostsStillLoads(): void
    {
        $this->writeFile(withSearchHosts: false);

        $referrers = $this->referrers();
        self::assertFalse($referrers->isStale());
        self::assertSame(\count(self::HOSTS), $referrers->hostCount());
        self::assertSame('claude', $referrers->match('claude.ai', ''));
        self::assertSame('chatgpt', $referrers->match('www.google.fr', 'chatgpt.com'));
        self::assertSame('copilot', $referrers->match('', 'copilot.com?form=MG0AUO'));
    }

    /**
     * The upgrade trap: 0.10.x may already have stored the list at the ranges
     * version that introduced ``searchHosts``, dropping the key. Reporting
     * that version would keep the sync conditional and every answer a 304
     * until the backend's version next moved, so no version is reported, the
     * next sync is unconditional, and its 200 brings the key.
     */
    public function testAFileWithoutSearchHostsReportsNoVersion(): void
    {
        $this->writeFile(withSearchHosts: false);

        $referrers = $this->referrers();
        self::assertNull($referrers->version());
        self::assertSame($this->clock->now()->getTimestamp(), $referrers->fetchedAt());

        // A restamp confirms freshness, never the shape: the key stays absent.
        self::assertTrue($referrers->restamp());
        self::assertArrayNotHasKey('searchHosts', (array) json_decode((string) file_get_contents($this->dir.'/'.AiReferrers::FILE), true));
        self::assertNull($this->referrers()->version());
    }

    public function testAFileWithAnEmptySearchHostsListReportsItsVersion(): void
    {
        $this->writeFile(withSearchHosts: true);

        self::assertSame('3f2a9c1e0b7d', $this->referrers()->version());
    }

    #[DataProvider('visits')]
    public function testMatchesTheHostFirstThenTheUtmSource(string $host, string $utmSource, string $platform): void
    {
        $this->store();

        self::assertSame($platform, $this->referrers()->match($host, $utmSource));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function unnormalised(): iterable
    {
        yield 'upper-case host' => ['ChatGPT.COM', '', 'chatgpt'];
        yield 'host with a port' => ['claude.ai:443', '', 'claude'];
        yield 'fully qualified host' => ['claude.ai.', '', 'claude'];
        yield 'fully qualified host with a port' => ['Claude.AI.:8443', '', 'claude'];
        yield 'utm_source with spaces and capitals' => ['', '  Perplexity.AI ', 'perplexity'];
        yield 'a bracketed IPv6 host with a port' => ['[2001:db8::1]:8080', '', 'none'];
    }

    #[DataProvider('unnormalised')]
    public function testNormalisesWhatTheBrowserSent(string $host, string $utmSource, string $platform): void
    {
        $this->store();

        self::assertSame($platform, $this->referrers()->match($host, $utmSource));
    }

    /**
     * AC7: a list nobody has confirmed for two weeks is not trusted to name a
     * platform, the same rule as the vendor ranges.
     */
    public function testAStaleListMatchesNothing(): void
    {
        $this->store();

        $this->clock->modify('+14 days');
        self::assertSame('chatgpt', $this->referrers()->match('chatgpt.com', ''), '14 days is still fresh');

        $this->clock->modify('+1 second');
        $referrers = $this->referrers();
        self::assertTrue($referrers->isStale());
        self::assertSame(PageViewRules::PLATFORM_NONE, $referrers->match('chatgpt.com', 'chatgpt.com'));
    }

    /**
     * AC7: the synced list deleted from buffer_dir.
     */
    public function testADeletedListMatchesNothing(): void
    {
        $this->store();
        unlink($this->dir.'/'.AiReferrers::FILE);

        self::assertSame(PageViewRules::PLATFORM_NONE, $this->referrers()->match('chatgpt.com', ''));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableFiles(): iterable
    {
        yield 'empty' => [''];
        yield 'not json' => ['<html>'];
        yield 'no version' => ['{"fetched_at":1,"hosts":{"chatgpt.com":"chatgpt"},"utmSources":{}}'];
        yield 'no fetched_at' => ['{"version":"v","hosts":{"chatgpt.com":"chatgpt"},"utmSources":{}}'];
        yield 'hosts not a map' => ['{"version":"v","fetched_at":1,"hosts":"chatgpt.com","utmSources":{}}'];
    }

    #[DataProvider('unreadableFiles')]
    public function testAnUnreadableListMatchesNothing(string $contents): void
    {
        $this->clock = new MockClock('@1', 'UTC');
        file_put_contents($this->dir.'/'.AiReferrers::FILE, $contents);

        self::assertSame(PageViewRules::PLATFORM_NONE, $this->referrers()->match('chatgpt.com', 'chatgpt.com'));
    }

    /**
     * AC12: a view with no referrer and no utm_source — nearly every view —
     * costs no file read at all.
     */
    public function testAViewWithNothingToMatchNeverTouchesTheFile(): void
    {
        $this->store();
        OpenRecordingStream::register();
        $referrers = new AiReferrers(new BufferDirectory(OpenRecordingStream::url($this->dir)), $this->clock);

        self::assertSame(PageViewRules::PLATFORM_NONE, $referrers->match('', ''));
        self::assertSame([], OpenRecordingStream::touched());

        // The control: the same object does read it once there is something to match.
        self::assertSame('chatgpt', $referrers->match('chatgpt.com', ''));
        self::assertSame([AiReferrers::FILE], OpenRecordingStream::touched());
    }

    public function testStoresItsOwnSmallFileBesideTheVendorRanges(): void
    {
        self::assertTrue($this->store());

        $decoded = json_decode((string) file_get_contents($this->dir.'/'.AiReferrers::FILE), true);
        self::assertSame([
            'version' => '3f2a9c1e0b7d',
            'fetched_at' => $this->clock->now()->getTimestamp(),
            'hosts' => self::HOSTS,
            'utmSources' => self::UTM_SOURCES,
            'searchHosts' => self::SEARCH_HOSTS,
        ], $decoded);
        self::assertSame(0o660, fileperms($this->dir.'/'.AiReferrers::FILE) & 0o777, 'shared with the flush user');
        self::assertSame([AiReferrers::FILE], array_values(array_diff(scandir($this->dir) ?: [], ['.', '..'])), 'no temporary file left behind');

        $referrers = $this->referrers();
        self::assertSame('3f2a9c1e0b7d', $referrers->version());
        self::assertSame(6, $referrers->hostCount());
        self::assertSame(6, $referrers->utmSourceCount());
        self::assertFalse($referrers->isStale());
    }

    /**
     * Empty lists stay JSON objects on disk, so the file always has one shape.
     */
    public function testStoresEmptyListsAsObjects(): void
    {
        self::assertTrue($this->referrers()->store('3f2a9c1e0b7d', [], [], []));

        self::assertStringContainsString('"hosts":{},"utmSources":{},"searchHosts":[]', (string) file_get_contents($this->dir.'/'.AiReferrers::FILE));
        self::assertSame(PageViewRules::PLATFORM_NONE, $this->referrers()->match('chatgpt.com', 'chatgpt.com'));
        self::assertFalse($this->referrers()->isStale(), 'a confirmed empty list is fresh, it just matches nothing');
    }

    public function testRestampConfirmsTheStoredListAsFresh(): void
    {
        $this->store();
        $this->clock->modify('+20 days');
        self::assertTrue($this->referrers()->isStale());

        self::assertTrue($this->referrers()->restamp());

        $referrers = $this->referrers();
        self::assertFalse($referrers->isStale());
        self::assertSame($this->clock->now()->getTimestamp(), $referrers->fetchedAt());
        self::assertSame('chatgpt', $referrers->match('chatgpt.com', ''));
        self::assertFalse((new AiReferrers(new BufferDirectory($this->dir.'/missing'), $this->clock))->restamp(), 'nothing stored, nothing to confirm');
    }

    public function testResetPicksUpAListAnotherProcessReplaced(): void
    {
        $this->store();
        $worker = $this->referrers();
        self::assertSame('none', $worker->match('', 'newsletter'));

        $this->referrers()->store('a1b2c3d4e5f6', self::HOSTS, ['newsletter' => 'claude'], self::SEARCH_HOSTS);

        self::assertSame('none', $worker->match('', 'newsletter'), 'held for the request');
        $worker->reset();
        self::assertSame('claude', $worker->match('', 'newsletter'));
        self::assertSame('a1b2c3d4e5f6', $worker->version());
    }

    /**
     * The block comes from the network: only lower-cased string keys and
     * platform names a counter key and the wire can carry survive.
     */
    public function testParseKeepsOnlyWellFormedEntries(): void
    {
        self::assertSame(
            [
                'hosts' => ['chatgpt.com' => 'chatgpt', 'claude.ai' => 'claude'],
                'utmSources' => ['perplexity' => 'perplexity'],
                'searchHosts' => ['google.*', 'bing.com'],
            ],
            AiReferrers::parse([
                'hosts' => [
                    'chatgpt.com' => 'chatgpt',
                    'Claude.AI' => 'claude',
                    '' => 'chatgpt',
                    'gemini.google.com' => 'Gemini',
                    'copilot.microsoft.com' => 'co pilot',
                    'evil.example' => "chatgpt\tpath",
                    'perplexity.ai' => str_repeat('p', 33),
                    'number.example' => 7,
                    'at.example' => 'chat@gpt',
                ],
                'utmSources' => ['perplexity' => 'perplexity', 'none' => ['x']],
                'searchHosts' => ['Google.*', '', '  ', 7, ['bing.com'], ' bing.com '],
            ]),
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function absentBlocks(): iterable
    {
        yield 'missing' => [null];
        yield 'a string' => ['chatgpt.com'];
        yield 'a list' => [['chatgpt.com']];
        yield 'lists not maps' => [['hosts' => 'chatgpt.com', 'utmSources' => 3, 'searchHosts' => 'google.*']];
        yield 'searchHosts a map' => [['searchHosts' => ['google' => 'google.*']]];
    }

    #[DataProvider('absentBlocks')]
    public function testParseReadsAMissingOrMalformedBlockAsEmptyLists(mixed $block): void
    {
        self::assertSame(['hosts' => [], 'utmSources' => [], 'searchHosts' => []], AiReferrers::parse($block));
    }

    private function writeFile(bool $withSearchHosts): void
    {
        file_put_contents(
            $this->dir.'/'.AiReferrers::FILE,
            json_encode([
                'version' => '3f2a9c1e0b7d',
                'fetched_at' => $this->clock->now()->getTimestamp(),
                'hosts' => self::HOSTS,
                'utmSources' => self::UTM_SOURCES,
            ] + ($withSearchHosts ? ['searchHosts' => []] : [])),
        );
    }

    private function store(): bool
    {
        return $this->referrers()->store('3f2a9c1e0b7d', self::HOSTS, self::UTM_SOURCES, self::SEARCH_HOSTS);
    }

    private function referrers(): AiReferrers
    {
        return new AiReferrers(new BufferDirectory($this->dir), $this->clock);
    }
}
