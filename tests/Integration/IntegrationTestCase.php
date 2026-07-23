<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Client\FetchResult;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeFetcher;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class IntegrationTestCase extends WebTestCase
{
    protected static bool $withRobotsRoute = false;

    protected const BODIES = [
        'llms.txt' => 'llms body',
        'ai.json' => '{"a":1}',
        'schema.json' => '{"@context":"https://schema.org","@type":"TouristDestination"}',
        '.well-known/mcp.json' => '{"m":1}',
        '.well-known/agent-card.json' => '{"c":2}',
    ];

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', false, static::$withRobotsRoute);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Symfony's DebugHandlersListener leaves exception handlers
        // registered, which PHPUnit ≥ 10 reports as risky — pop them all.
        while (true) {
            $handler = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $handler) {
                break;
            }
            restore_exception_handler();
        }
    }

    /**
     * Boots a client and empties the shared filesystem pool so tests are
     * isolated despite the persistent cache dir.
     */
    protected function bootClient(): KernelBrowser
    {
        $client = static::createClient();
        static::getContainer()->get('cache.app')->clear();

        return $client;
    }

    protected function fetcher(): FakeFetcher
    {
        $fetcher = static::getContainer()->get(FakeFetcher::class);
        \assert($fetcher instanceof FakeFetcher);

        return $fetcher;
    }

    protected function serveRequiredFiles(): void
    {
        foreach (static::BODIES as $path => $body) {
            $this->fetcher()->on('/'.$path, FetchResult::http(200, $body));
        }
    }

    protected function runCommand(string $name, array $input = []): CommandTester
    {
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find($name));
        $tester->execute($input);

        return $tester;
    }

    protected function refresh(): CommandTester
    {
        return $this->runCommand('gt:refresh', ['--force' => true]);
    }
}
