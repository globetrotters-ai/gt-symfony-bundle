<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Client\FetchResult;
use Globetrotters\AiPresenceBundle\Settings\Options;

final class RefreshStaleServeTest extends IntegrationTestCase
{
    public function testFailedRefreshKeepsServingTheLastGoodBundle(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        self::assertSame(0, $this->refresh()->getStatusCode());

        // Upstream starts failing: the pull aborts, the old bundle serves on.
        $this->fetcher()->on('/llms.txt', FetchResult::http(500, ''));
        $failed = $this->refresh();
        self::assertSame(1, $failed->getStatusCode());

        $client->request('GET', '/llms.txt');
        self::assertSame('llms body', $client->getResponse()->getContent());

        $options = static::getContainer()->get(Options::class);
        \assert($options instanceof Options);
        $options->reset();
        self::assertStringContainsString('/llms.txt', (string) $options->state()['last_error']);
    }

    public function testIdenticalRepullReportsUnchanged(): void
    {
        $this->bootClient();
        $this->serveRequiredFiles();

        $first = $this->refresh();
        self::assertStringContainsString('content changed', $first->getDisplay());

        $second = $this->refresh();
        self::assertSame(0, $second->getStatusCode());
        self::assertStringContainsString('content unchanged', $second->getDisplay());
    }

    public function testRefreshWithoutForceRespectsInterval(): void
    {
        $this->bootClient();
        $this->serveRequiredFiles();

        // First run is due immediately (fresh install).
        $first = $this->runCommand('gt:refresh');
        self::assertSame(0, $first->getStatusCode());

        // Immediately after, the daily cadence is not due yet.
        $second = $this->runCommand('gt:refresh');
        self::assertSame(0, $second->getStatusCode());
        self::assertStringContainsString('not due', $second->getDisplay());
    }

    public function testStatusReportsStateAndDrift(): void
    {
        $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $status = $this->runCommand('gt:status');
        self::assertSame(0, $status->getStatusCode());
        self::assertStringContainsString('yes ('.\Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel::WEBSITE_URL.')', $status->getDisplay());

        // Upstream now advertises a newer marker: --remote reports drift.
        $this->fetcher()->on(
            '/.well-known/globetrotters-apex-version.json',
            FetchResult::http(200, '{"version":"2099-01-01-000000"}'),
        );
        $remote = $this->runCommand('gt:status', ['--remote' => true]);
        self::assertStringContainsString('2099-01-01-000000', $remote->getDisplay());
        self::assertStringContainsString('newer version', $remote->getDisplay());
    }
}
