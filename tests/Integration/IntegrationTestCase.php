<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\DroppedCounter;
use Globetrotters\AiPresenceBundle\Analytics\EventBuffer;
use Globetrotters\AiPresenceBundle\Analytics\NdjsonEventStore;
use Globetrotters\AiPresenceBundle\Client\FetchResult;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeFetcher;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeIngestTransport;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class IntegrationTestCase extends WebTestCase
{
    protected static bool $withRobotsRoute = false;
    protected static bool $withReporting = false;
    protected static bool $withOpportunisticFlush = false;

    protected const BODIES = [
        'llms.txt' => 'llms body',
        'ai.json' => '{"a":1}',
        'schema.json' => '{"@context":"https://schema.org","@type":"TouristDestination"}',
        '.well-known/mcp.json' => '{"m":1}',
        '.well-known/agent-card.json' => '{"c":2}',
    ];

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', false, static::$withRobotsRoute, static::$withReporting, static::$withOpportunisticFlush);
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
        $this->clearBuffer();

        return $client;
    }

    /**
     * Empties the reporting lane's directory: unlike the cache pool it is
     * plain files, and it outlives a kernel shutdown.
     */
    protected function clearBuffer(): void
    {
        $kernel = static::$kernel;
        \assert($kernel instanceof TestKernel);

        TempDirectory::remove($kernel->bufferDir());
    }

    protected function transport(): FakeIngestTransport
    {
        $transport = static::getContainer()->get(FakeIngestTransport::class);
        \assert($transport instanceof FakeIngestTransport);

        return $transport;
    }

    /**
     * A view on the same buffer the kernel writes to, built directly rather
     * than pulled from the container: it is a filesystem structure, and reading
     * it the same way an out-of-process flush would is the point.
     */
    protected function buffer(): EventBuffer
    {
        $kernel = static::$kernel;
        \assert($kernel instanceof TestKernel);

        $directory = new BufferDirectory($kernel->bufferDir());

        return new EventBuffer(new NdjsonEventStore($directory), new DroppedCounter($directory));
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
