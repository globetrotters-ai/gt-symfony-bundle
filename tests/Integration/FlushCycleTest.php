<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Analytics\IngestResult;
use Globetrotters\AiPresenceBundle\GlobetrottersAiPresenceBundle;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel;
use Symfony\Component\Console\Command\Command;

/**
 * The console flush lane, end to end: serve, capture, flush, drain.
 */
final class FlushCycleTest extends IntegrationTestCase
{
    protected static bool $withReporting = true;

    public function testFlushesCapturedHitsAndDrainsTheBuffer(): void
    {
        $client = $this->bootClient();
        $client->disableReboot();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', '/llms.txt', server: ['HTTP_USER_AGENT' => 'ClaudeBot/1.0']);
        $client->request('GET', '/ai.json', server: ['HTTP_USER_AGENT' => 'ClaudeBot/1.0']);
        self::assertSame(2, $this->buffer()->count());

        $tester = $this->runCommand('gt:presence:flush', ['--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(0, $this->buffer()->count());

        $transport = $this->transport();
        self::assertCount(1, $transport->sent);
        self::assertSame(TestKernel::INGEST_ENDPOINT, $transport->sent[0]['url']);
        self::assertSame(TestKernel::INGEST_TOKEN, $transport->sent[0]['token']);

        $envelope = $transport->envelopes()[0];
        self::assertSame('symfony-bundle/'.GlobetrottersAiPresenceBundle::VERSION, $envelope['producer']);
        self::assertSame(1.0, $envelope['sampleRate']);
        self::assertSame(0, $envelope['dropped']);
        self::assertSame(['/llms.txt', '/ai.json'], array_column($envelope['events'], 'path'));
    }

    public function testARejectedFlushKeepsTheEventsForAnIdenticalRetry(): void
    {
        $client = $this->bootClient();
        $client->disableReboot();
        $this->serveRequiredFiles();
        $this->refresh();
        $this->transport()->willReturn(IngestResult::error('Connection timed out'));

        $client->request('GET', '/llms.txt');
        $tester = $this->runCommand('gt:presence:flush', ['--force' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('same UUIDs', $tester->getDisplay());
        self::assertSame(1, $this->buffer()->count());

        $this->runCommand('gt:presence:flush', ['--force' => true]);

        $transport = $this->transport();
        self::assertCount(2, $transport->sent);
        self::assertSame($transport->sent[0]['json'], $transport->sent[1]['json'], 'a retry re-sends the same payload byte for byte');
        self::assertSame(0, $this->buffer()->count());
    }

    public function testTheIntervalHoldsWithoutForce(): void
    {
        $client = $this->bootClient();
        $client->disableReboot();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', '/llms.txt');
        $this->runCommand('gt:presence:flush', ['--force' => true]);

        $client->request('GET', '/ai.json');
        $tester = $this->runCommand('gt:presence:flush');

        self::assertStringContainsString('Not due yet', $tester->getDisplay());
        self::assertCount(1, $this->transport()->sent);
        self::assertSame(1, $this->buffer()->count());
    }

    public function testAnEmptyBufferStillCallsHome(): void
    {
        // The backend stamps an install-health watermark on every batch, empty
        // or not: the producer having called home is the signal.
        $this->bootClient();

        $tester = $this->runCommand('gt:presence:flush', ['--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nothing buffered', $tester->getDisplay());
        self::assertCount(1, $this->transport()->sent);
        self::assertSame([], $this->transport()->envelopes()[0]['events']);
    }

    public function testARejectedEmptyHeartbeatFailsTheCommand(): void
    {
        $this->bootClient();
        $this->transport()->willReturn(IngestResult::http(503));

        $tester = $this->runCommand('gt:presence:flush', ['--force' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('heartbeat not accepted', $tester->getDisplay());
    }

    public function testStatusReportsReportingHealth(): void
    {
        $client = $this->bootClient();
        $client->disableReboot();
        $this->serveRequiredFiles();
        $this->refresh();
        $client->request('GET', '/llms.txt');

        $before = $this->runCommand('gt:status')->getDisplay();
        self::assertStringContainsString('Agent-traffic reporting', $before);
        self::assertStringContainsString('no flush has ever been accepted', $before);

        $this->runCommand('gt:presence:flush', ['--force' => true]);
        $after = $this->runCommand('gt:status')->getDisplay();

        self::assertStringContainsString('Reporting normally', $after);
        self::assertStringContainsString('202 to a bad token', $after, 'acceptance is hand-off, not confirmation');
        self::assertStringContainsString('command', $after);
    }

    public function testStatusNeverPrintsTheIngestToken(): void
    {
        $this->bootClient();

        $display = $this->runCommand('gt:status')->getDisplay();

        self::assertStringNotContainsString(TestKernel::INGEST_TOKEN, $display);
        self::assertStringContainsString('…abcd', $display);
    }
}
