<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Command;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Client\FetchResult;
use Globetrotters\AiPresenceBundle\Command\RefreshCommand;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Globetrotters\AiPresenceBundle\Sync\ArtefactSync;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RefreshCommandTest extends TestCase
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
     * After a deploy repoints website_url, the state still says the last
     * refresh was recent. Forgetting the previous source resets it, so the next
     * cron run pulls the new source instead of waiting out the interval.
     */
    public function testARepointedInstallIsRefreshedWithoutWaitingForTheInterval(): void
    {
        $pool = new ArrayAdapter();
        $clock = new MockClock('2026-09-10 10:00:00', 'UTC');
        (new ArtefactCache($pool, 'https://lyon.globetrotters.ai'))->store(['llms.txt' => 'lyon'], 'lyon-v1', 0);
        $options = new Options($pool, self::BASE_URL, 'daily', '/');
        $options->updateState(['last_refresh' => $clock->now()->getTimestamp()]);
        $fetcher = (new FakeFetcher())->fallback(FetchResult::http(404, ''));
        foreach (self::BODIES as $path => $body) {
            $fetcher->on('/'.$path, FetchResult::http(200, $body));
        }
        $cache = new ArtefactCache($pool, self::BASE_URL);

        $tester = $this->tester($fetcher, $cache, $options, $clock);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Dropped the bundle cached for a previous website_url', $tester->getDisplay());
        self::assertSame('hello', $cache->get('llms.txt'));
    }

    public function testAClearedInstallDropsTheBundleItNoLongerServes(): void
    {
        $pool = new ArrayAdapter();
        $clock = new MockClock('2026-09-10 10:00:00', 'UTC');
        (new ArtefactCache($pool, self::BASE_URL))->store(['llms.txt' => 'hello'], 'v1', 0);
        $options = new Options($pool, '', 'daily', '/');
        $options->updateState(['installed_version' => 'v1']);
        $cache = new ArtefactCache($pool, '');

        $tester = $this->tester(new FakeFetcher(), $cache, $options, $clock);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No website_url configured', $tester->getDisplay());
        self::assertFalse($cache->holdsForeignBundle());
        self::assertSame('', $options->state()['installed_version']);
    }

    private function tester(FakeFetcher $fetcher, ArtefactCache $cache, Options $options, MockClock $clock): CommandTester
    {
        return new CommandTester(new RefreshCommand(new ArtefactSync($fetcher, $cache, $options, $clock), $options, $clock));
    }
}
