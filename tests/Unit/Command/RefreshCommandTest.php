<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Command;

use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Client\FetchResult;
use Globetrotters\AiPresenceBundle\Command\RefreshCommand;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Globetrotters\AiPresenceBundle\Sync\ArtefactSync;
use Globetrotters\AiPresenceBundle\Sync\RefreshLock;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeFetcher;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
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
        '.well-known/ai-catalog.json' => '{"specVersion":"1.0"}',
        '.well-known/agent-skills/index.json' => '{"skills":[]}',
        'agent.json' => '{"name":"Nantes"}',
        'AGENTS.md' => "# Nantes\n",
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

    /**
     * A process with no website_url is as likely a cron job or worker missing
     * the env var as a withdrawn presence. The web tier already refuses the
     * bundle by its own configuration; deleting it here would take a correctly
     * configured site dark on every run.
     */
    public function testAProcessWithNoWebsiteUrlNeverDeletesTheBundle(): void
    {
        $pool = new ArrayAdapter();
        $clock = new MockClock('2026-09-10 10:00:00', 'UTC');
        $served = new ArtefactCache($pool, self::BASE_URL);
        $served->store(['llms.txt' => 'hello'], 'v1', 0);
        $options = new Options($pool, '', 'daily', '/');
        $options->updateState(['installed_version' => 'v1']);
        $cache = new ArtefactCache($pool, '');
        $sync = new ArtefactSync(new FakeFetcher(), $cache, $options, $clock);

        $tester = new CommandTester(new RefreshCommand($sync, $options));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No website_url configured', $tester->getDisplay());
        // The scheduler lane calls run() directly.
        self::assertFalse($sync->run()->isSuccess());

        self::assertNull($cache->get('llms.txt'), 'this process still serves none of it');
        $served->reset();
        self::assertSame('hello', $served->get('llms.txt'));
        self::assertSame('v1', (new Options($pool, self::BASE_URL, 'daily', '/'))->state()['installed_version']);
    }

    /**
     * Cron and the Scheduler (or two cron entries) firing together: the second
     * runner exits cleanly instead of pulling the whole set a second time.
     */
    public function testASecondRefreshSkipsWhileOneIsRunning(): void
    {
        $dir = TempDirectory::make();
        try {
            $pool = new ArrayAdapter();
            $clock = new MockClock('2026-10-19 04:00:00', 'UTC');
            $options = new Options($pool, self::BASE_URL, 'daily', '/');
            $fetcher = new FakeFetcher();
            $lock = new RefreshLock(new BufferDirectory($dir));
            $sync = new ArtefactSync($fetcher, new ArtefactCache($pool, self::BASE_URL), $options, $clock);
            $tester = new CommandTester(new RefreshCommand($sync, $options, null, $lock));

            $code = $lock->withLock(static fn (): int => $tester->execute(['--force' => true]));

            self::assertSame(Command::SUCCESS, $code);
            self::assertStringContainsString('Another refresh is already running', $tester->getDisplay());
            self::assertSame([], $fetcher->requested, 'nothing was pulled');
        } finally {
            TempDirectory::remove($dir);
        }
    }

    public function testAPullStampsWhoPulledIt(): void
    {
        $pool = new ArrayAdapter();
        $clock = new MockClock('2026-10-19 04:00:01', 'UTC');
        $options = new Options($pool, self::BASE_URL, 'daily', '/');
        $fetcher = new FakeFetcher();
        foreach (self::BODIES as $path => $body) {
            $fetcher->on('/'.$path, FetchResult::http(200, $body));
        }
        $fetcher->on('/.well-known/globetrotters-apex-version.json', FetchResult::http(200, '{"version":"2026-10-18-050325"}'));
        $sync = new ArtefactSync($fetcher, new ArtefactCache($pool, self::BASE_URL), $options, $clock, '/srv/site');

        self::assertSame(Command::SUCCESS, (new CommandTester(new RefreshCommand($sync, $options)))->execute([]));

        $stamp = $options->pulledBy();
        self::assertNotNull($stamp);
        self::assertSame('2026-10-18-050325', $stamp['version']);
        self::assertSame($clock->now()->getTimestamp(), $stamp['at']);
        self::assertSame('/srv/site', $stamp['project_dir']);
        self::assertSame((string) gethostname(), $stamp['hostname']);
        self::assertNotSame('', $stamp['user']);
    }

    private function tester(FakeFetcher $fetcher, ArtefactCache $cache, Options $options, MockClock $clock): CommandTester
    {
        return new CommandTester(new RefreshCommand(new ArtefactSync($fetcher, $cache, $options, $clock), $options));
    }
}
