<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Analytics\AiUserAgents;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel;
use Symfony\Component\Clock\NativeClock;

/**
 * The AI crawler registry the ranges sync stored in ``buffer_dir`` reaches
 * the served robots.txt, and ``gt:status`` reports it.
 */
final class RobotsSyncedRegistryTest extends IntegrationTestCase
{
    protected static bool $withReporting = true;

    public function testASyncedRegistryIsWhatRobotsTxtNames(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();
        $this->agents()->store('v6', AiUserAgents::parse([['name' => 'GPTBot'], ['name' => 'NewAI-Bot']]));

        $client->request('GET', '/robots.txt');
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString("User-agent: NewAI-Bot\nAllow: /", $content);
        self::assertStringNotContainsString('User-agent: CCBot', $content, 'the synced list replaces the compiled one');
    }

    public function testWithoutOneTheCompiledRegistryIsNamed(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', '/robots.txt');

        self::assertStringContainsString("User-agent: CCBot\nAllow: /", (string) $client->getResponse()->getContent());
    }

    public function testStatusShowsTheAiCrawlerRegistry(): void
    {
        $this->bootClient();

        self::assertMatchesRegularExpression('/AI crawler registry\s+not fetched yet/', $this->runCommand('gt:status')->getDisplay());

        $this->agents()->store('3f2a9c1e0b7d', [['name' => 'GPTBot'], ['name' => 'ClaudeBot']]);

        self::assertMatchesRegularExpression('/AI crawler registry\s+fresh, version 3f2a9c1e0b7d, 2 agents/', $this->runCommand('gt:status')->getDisplay());
    }

    public function testStatusTellsAnEmptyRegistryFromAStaleOne(): void
    {
        $this->bootClient();
        $this->agents()->store('3f2a9c1e0b7d', []);

        self::assertMatchesRegularExpression('/AI crawler registry\s+empty \(no registry/', $this->runCommand('gt:status')->getDisplay());
    }

    private function agents(): AiUserAgents
    {
        $kernel = static::$kernel;
        \assert($kernel instanceof TestKernel);

        return new AiUserAgents(new BufferDirectory($kernel->bufferDir()), new NativeClock());
    }
}
