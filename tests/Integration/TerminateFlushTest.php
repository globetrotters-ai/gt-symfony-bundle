<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Analytics\Event;
use Globetrotters\AiPresenceBundle\Client\FetchResult;

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

    /**
     * With events waiting and the interval due, only a served artefact may
     * trigger the flush: not the application's own pages, not robots.txt, and
     * not the sitemap or the IndexNow key, which are not agent traffic.
     */
    public function testOnlyAServedArtefactTriggersTheFlush(): void
    {
        $key = 'e715a2e7bf3c4a1d8e0b6f9c2d5a7e14';
        $client = $this->bootClient();
        $client->disableReboot();
        $this->serveRequiredFiles();
        $this->fetcher()->on('/.well-known/globetrotters-apex-version.json', FetchResult::http(200, (string) json_encode(['version' => 'v1', 'indexnowKey' => $key])));
        $this->refresh();
        $this->buffer()->append(new Event('00000000-0000-4000-8000-000000000001', '2026-08-11T09:14:22Z', '/llms.txt', 'ClaudeBot/1.0', '160.79.104.10', '', 200, 9));

        foreach (['/', '/interior', '/robots.txt', '/ai-sitemap.xml', '/'.$key.'.txt'] as $path) {
            $client->request('GET', $path);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $path);
        }
        self::assertCount(0, $this->transport()->sent);

        $client->request('GET', '/llms.txt');
        self::assertCount(1, $this->transport()->sent);
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
