<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\AiUserAgents;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The synced AI crawler registry: validation, storage and freshness.
 */
final class AiUserAgentsTest extends TestCase
{
    private string $dir;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
        $this->clock = new MockClock('2026-10-27 10:00:00', 'UTC');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    public function testParseKeepsNamesAndFallbackGroupsInOrder(): void
    {
        $list = [
            ['name' => 'GPTBot'],
            ['name' => 'Applebot', 'fallbackGroup' => 'Googlebot'],
            ['name' => 'NewAI-Bot'],
        ];

        self::assertSame($list, AiUserAgents::parse($list));
    }

    /**
     * An invalid entry is skipped, never repaired: a name lands verbatim on a
     * ``User-agent:`` line, so anything but a product token could inject rules.
     */
    public function testParseSkipsInvalidEntries(): void
    {
        self::assertSame([['name' => 'GPTBot']], AiUserAgents::parse([
            ['name' => 'GPTBot'],
            ['name' => "Evil\nDisallow: /"],
            ['name' => 'Bot/1.0'],
            ['name' => 'Two Words'],
            ['name' => ''],
            ['name' => 42],
            [],
            'GPTBot',
            ['name' => 'Applebot', 'fallbackGroup' => 'Google bot'],
            ['name' => 'Applebot', 'fallbackGroup' => null],
        ]));
    }

    #[DataProvider('unusableLists')]
    public function testParseReadsAnUnusableListAsEmpty(mixed $value): void
    {
        self::assertSame([], AiUserAgents::parse($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableLists(): iterable
    {
        yield 'absent' => [null];
        yield 'a string' => ['GPTBot'];
        yield 'an object' => [['name' => 'GPTBot']];
        yield 'over 64' => [array_fill(0, AiUserAgents::MAX_AGENTS + 1, ['name' => 'Bot'])];
        yield 'no entries' => [[]];
    }

    public function testExactly64EntriesAreKept(): void
    {
        self::assertCount(AiUserAgents::MAX_AGENTS, AiUserAgents::parse(array_fill(0, AiUserAgents::MAX_AGENTS, ['name' => 'Bot'])));
    }

    public function testAgentsIsNullWhenNothingIsStored(): void
    {
        self::assertNull($this->agents()->agents());
        self::assertNull($this->agents()->version());
        self::assertNull($this->agents()->fetchedAt());
    }

    public function testAStoredListIsServedAndVersioned(): void
    {
        self::assertTrue($this->agents()->store('v1', [['name' => 'GPTBot']]));

        self::assertSame([['name' => 'GPTBot']], $this->agents()->agents());
        self::assertSame('v1', $this->agents()->version());
        self::assertSame(1, $this->agents()->count());
    }

    public function testAListOlderThan14DaysIsNotServed(): void
    {
        $this->agents()->store('v1', [['name' => 'GPTBot']]);
        $this->clock->modify('+'.(AiUserAgents::STALE_AFTER_SECONDS + 1).' seconds');

        self::assertNull($this->agents()->agents());
        self::assertTrue($this->agents()->isStale());
        self::assertSame('v1', $this->agents()->version());
    }

    public function testRestampMakesAStaleListCurrentAgain(): void
    {
        $this->agents()->store('v1', [['name' => 'GPTBot']]);
        $this->clock->modify('+20 days');

        self::assertTrue($this->agents()->restamp());

        self::assertSame([['name' => 'GPTBot']], $this->agents()->agents());
    }

    public function testRestampWithoutAListStoresNothing(): void
    {
        self::assertFalse($this->agents()->restamp());

        self::assertFileDoesNotExist($this->dir.'/'.AiUserAgents::FILE);
    }

    /**
     * A backend from before the registry joined the ranges document stores an
     * empty list at the document's version, so the next check stays
     * conditional; robots.txt then uses the compiled list.
     */
    public function testAnEmptyListIsVersionedButNotServed(): void
    {
        $this->agents()->store('v1', []);

        self::assertSame('v1', $this->agents()->version());
        self::assertNull($this->agents()->agents());
    }

    /**
     * A file edited by hand bypasses the sync, so what is read back is
     * validated again.
     */
    public function testATamperedFileIsValidatedOnRead(): void
    {
        file_put_contents($this->dir.'/'.AiUserAgents::FILE, json_encode([
            'version' => 'v1',
            'fetched_at' => $this->clock->now()->getTimestamp(),
            'agents' => [['name' => "GPTBot\nAllow: /"], ['name' => 'ClaudeBot']],
        ]));

        self::assertSame([['name' => 'ClaudeBot']], $this->agents()->agents());
    }

    public function testResetPicksUpAListAnotherProcessReplaced(): void
    {
        $agents = $this->agents();
        $agents->store('v1', [['name' => 'GPTBot']]);
        $this->agents()->store('v2', [['name' => 'ClaudeBot']]);

        self::assertSame('v1', $agents->version(), 'read once per process');
        $agents->reset();
        self::assertSame([['name' => 'ClaudeBot']], $agents->agents());
    }

    private function agents(): AiUserAgents
    {
        return new AiUserAgents(new BufferDirectory($this->dir), $this->clock);
    }
}
