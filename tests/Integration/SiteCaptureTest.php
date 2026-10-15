<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Analytics\Event;
use Globetrotters\AiPresenceBundle\Analytics\NdjsonEventStore;
use Globetrotters\AiPresenceBundle\Analytics\RangesFetch;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel;
use Globetrotters\AiPresenceBundle\Tests\Unit\Analytics\VendorRangesTest;

/**
 * Site bot capture through a real kernel: the gate, the no-IP promise on disk
 * and on the wire, the ranges lane and the capture context.
 */
final class SiteCaptureTest extends IntegrationTestCase
{
    protected static bool $withReporting = true;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';
    private const GPTBOT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)';

    public function testCapturesBotsOnApplicationPagesAndNothingElse(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', '/interior?q=secret', server: ['HTTP_USER_AGENT' => self::GPTBOT, 'REMOTE_ADDR' => '20.171.206.10']);
        $client->request('GET', '/interior', server: ['HTTP_USER_AGENT' => self::CHROME]);
        $client->request('POST', '/interior', server: ['HTTP_USER_AGENT' => self::GPTBOT]);
        $client->request('GET', '/_profiler/abc', server: ['HTTP_USER_AGENT' => self::GPTBOT]);
        $client->request('GET', '/?json', server: ['HTTP_USER_AGENT' => self::GPTBOT]);
        $client->request('GET', '/llms.txt', server: ['HTTP_USER_AGENT' => self::GPTBOT, 'REMOTE_ADDR' => '20.171.206.10']);
        $client->request('GET', '/ai-sitemap.xml', server: ['HTTP_USER_AGENT' => self::GPTBOT]);
        $client->request('POST', '/.well-known/globetrotters/pv', server: ['HTTP_USER_AGENT' => self::GPTBOT]);
        $client->request('GET', '/', server: ['HTTP_USER_AGENT' => '']);

        $events = $this->buffer()->claim(50);
        $site = array_values(array_filter($events, static fn (Event $event): bool => $event->isSite()));
        $artefacts = array_values(array_filter($events, static fn (Event $event): bool => !$event->isSite()));

        self::assertSame(['/interior', '/'], array_map(static fn (Event $event): string => $event->path(), $site));
        self::assertSame(['/llms.txt'], array_map(static fn (Event $event): string => $event->path(), $artefacts), 'artefacts keep their own lane');
        self::assertSame(200, $site[0]->status());
        self::assertNull($site[0]->verified(), 'no range list yet');

        $kernel = static::$kernel;
        \assert($kernel instanceof TestKernel);
        $raw = (string) file_get_contents($kernel->bufferDir().'/'.NdjsonEventStore::FILE);
        $siteLines = array_filter(explode("\n", $raw), static fn (string $line): bool => str_contains($line, '"kind":"site"'));
        self::assertCount(2, $siteLines);
        foreach ($siteLines as $line) {
            self::assertStringNotContainsString('20.171.206.10', $line);
            self::assertStringNotContainsString('"ip"', $line);
            self::assertStringNotContainsString('secret', $line);
        }
    }

    public function testRangesAreFetchedWithNoPublishedBundleAndVerifySiteEvents(): void
    {
        $client = $this->bootClient();
        $this->transport()->willReturnRanges(RangesFetch::http(200, VendorRangesTest::LIST));

        // Nothing is published upstream: the artefact sync fails, the ranges
        // do not depend on it.
        $this->refresh();
        self::assertCount(1, $this->transport()->rangeRequests);
        self::assertSame(TestKernel::INGEST_ENDPOINT.'/ranges', $this->transport()->rangeRequests[0]['url']);

        $client->request('GET', '/interior', server: ['HTTP_USER_AGENT' => self::GPTBOT, 'REMOTE_ADDR' => '20.171.206.10', 'HTTP_CF_RAY' => 'abc']);

        $this->runCommand('gt:presence:flush', ['--force' => true]);
        $envelope = $this->transport()->envelopes()[0];

        self::assertCount(1, $envelope['siteEvents']);
        self::assertTrue($envelope['siteEvents'][0]['verified']);
        self::assertSame('chatgpt', $envelope['siteEvents'][0]['vendor']);
        self::assertArrayNotHasKey('ip', $envelope['siteEvents'][0]);
        self::assertStringNotContainsString('20.171.206.10', $this->transport()->sent[0]['json']);
        self::assertTrue($envelope['captureContext']['siteCapture']);
        self::assertSame(['cf-ray'], $envelope['captureContext']['cdnMarkers']);
        self::assertSame('3f2a9c1e0b7d', $envelope['captureContext']['rangesVersion']);

        $status = $this->runCommand('gt:status')->getDisplay();
        self::assertStringContainsString('Site bot capture', $status);
        self::assertStringContainsString('fresh, version 3f2a9c1e0b7d', $status);
    }

    public function testAnApplicationPageNeverTriggersTheFlush(): void
    {
        $client = $this->bootClient();
        $client->request('GET', '/interior', server: ['HTTP_USER_AGENT' => self::GPTBOT]);

        self::assertSame([], $this->transport()->sent);
        self::assertSame(1, $this->buffer()->count());
    }
}
