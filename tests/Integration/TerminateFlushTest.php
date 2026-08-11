<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

/**
 * The no-worker lane: an application with no cron entry, no systemd unit and
 * no ``messenger:consume`` still reports, because kernel.terminate runs after
 * the response has been sent.
 */
final class TerminateFlushTest extends IntegrationTestCase
{
    protected static bool $withReporting = true;
    protected static bool $withOpportunisticFlush = true;

    public function testTrafficItselfDrivesTheFlushWithNoWorkerAnywhere(): void
    {
        $client = $this->bootClient();
        $client->disableReboot();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', '/llms.txt', server: ['HTTP_USER_AGENT' => 'ClaudeBot/1.0']);

        $transport = $this->transport();
        self::assertCount(1, $transport->sent, 'no console command and no worker ran');
        self::assertSame(['/llms.txt'], array_column($transport->envelopes()[0]['events'], 'path'));
        self::assertSame(0, $this->buffer()->count());
    }

    public function testTheServedResponseIsUnaffectedByTheFlush(): void
    {
        $client = $this->bootClient();
        $client->disableReboot();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', '/llms.txt');
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(static::BODIES['llms.txt'], $response->getContent());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function testItRateLimitsItselfHoweverMuchTrafficArrives(): void
    {
        $client = $this->bootClient();
        $client->disableReboot();
        $this->serveRequiredFiles();
        $this->refresh();

        foreach (range(1, 12) as $ignored) {
            $client->request('GET', '/llms.txt');
        }

        self::assertCount(1, $this->transport()->sent, 'at most one flush per 15 minutes regardless of traffic');
        self::assertSame(11, $this->buffer()->count());
    }

    public function testAFailedFlushLeavesTheEventsBufferedAndTheResponseIntact(): void
    {
        $client = $this->bootClient();
        $client->disableReboot();
        $this->serveRequiredFiles();
        $this->refresh();
        $this->transport()->fallback(\Globetrotters\AiPresenceBundle\Analytics\IngestResult::error('DNS failure'));

        $client->request('GET', '/llms.txt');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(static::BODIES['llms.txt'], $client->getResponse()->getContent());
        self::assertSame(1, $this->buffer()->count());
    }
}
