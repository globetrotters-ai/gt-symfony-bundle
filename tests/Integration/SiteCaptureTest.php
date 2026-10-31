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
    private const SIGNATURE_INPUT = 'sig1=("@authority" "signature-agent");created=1793356800;expires=1793356860;keyid="k1";alg="ed25519";tag="web-bot-auth"';

    /** The three Web Bot Auth headers of a signed Meta Muse request. */
    private const SIGNED = [
        'HTTP_SIGNATURE_AGENT' => '"https://www.meta.com"',
        'HTTP_SIGNATURE_INPUT' => self::SIGNATURE_INPUT,
        'HTTP_SIGNATURE' => 'sig1=:dGVzdA==:',
    ];

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

    public function testASignedRequestPassesTheGateWhateverItsUserAgent(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', '/interior', server: ['HTTP_USER_AGENT' => self::CHROME, 'HTTP_HOST' => 'www.acme-guide.example', 'REMOTE_ADDR' => '157.240.1.1'] + self::SIGNED);
        $client->request('GET', '/interior', server: ['HTTP_USER_AGENT' => self::CHROME, 'HTTP_SIGNATURE_AGENT' => '   ']);
        $client->request('GET', '/', server: ['HTTP_USER_AGENT' => self::CHROME]);

        $events = $this->buffer()->claim(50);
        self::assertCount(1, $events, 'the same browser without the header, or with a blank one, is not captured');
        self::assertTrue($events[0]->isSite());
        self::assertSame([
            'agent' => '"https://www.meta.com"',
            'input' => self::SIGNATURE_INPUT,
            'value' => 'sig1=:dGVzdA==:',
            'authority' => 'www.acme-guide.example',
        ], $events[0]->signature());

        $this->runCommand('gt:presence:flush', ['--force' => true]);
        $site = $this->transport()->envelopes()[0]['siteEvents'][0];
        self::assertSame($events[0]->signature(), $site['signature']);
        self::assertArrayNotHasKey('ip', $site);
        self::assertStringNotContainsString('157.240.1.1', $this->transport()->sent[0]['json']);
    }

    public function testPartialSignatureHeadersPassTheGateButCarryNoSignature(): void
    {
        $client = $this->bootClient();

        $client->request('GET', '/interior', server: ['HTTP_USER_AGENT' => self::CHROME, 'HTTP_SIGNATURE_AGENT' => '"https://www.meta.com"']);
        $client->request('GET', '/', server: ['HTTP_USER_AGENT' => self::CHROME] + array_diff_key(self::SIGNED, ['HTTP_SIGNATURE' => true]));
        $client->request('GET', '/interior', server: ['HTTP_USER_AGENT' => self::GPTBOT] + array_diff_key(self::SIGNED, ['HTTP_SIGNATURE_INPUT' => true]));

        $events = $this->buffer()->claim(50);
        self::assertCount(3, $events);
        foreach ($events as $event) {
            self::assertNull($event->signature());
            self::assertArrayNotHasKey('signature', $event->toPayload());
        }
    }

    public function testSignatureMembersAreTrimmedAndClipped(): void
    {
        $client = $this->bootClient();
        $long = 'sig1=:'.str_repeat('A', 2000).':';

        $client->request('GET', '/interior', server: [
            'HTTP_USER_AGENT' => self::CHROME,
            'HTTP_HOST' => 'www.acme-guide.example',
            'HTTP_SIGNATURE_AGENT' => "  \"https://www.meta.com\"\t",
            'HTTP_SIGNATURE_INPUT' => ' '.self::SIGNATURE_INPUT.' ',
            'HTTP_SIGNATURE' => $long,
        ]);

        $signature = $this->buffer()->claim(50)[0]->signature();
        self::assertNotNull($signature);
        self::assertSame('"https://www.meta.com"', $signature['agent'], 'trimmed, quotes kept');
        self::assertSame(self::SIGNATURE_INPUT, $signature['input']);
        self::assertSame(1024, \strlen($signature['value']));
        self::assertSame(substr($long, 0, 1024), $signature['value']);
    }

    public function testASignedArtefactRequestCarriesTheSignature(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', '/llms.txt', server: ['HTTP_USER_AGENT' => self::CHROME, 'HTTP_HOST' => 'www.acme-guide.example'] + self::SIGNED);
        $client->request('GET', '/llms.txt', server: ['HTTP_USER_AGENT' => self::GPTBOT]);

        $events = $this->buffer()->claim(50);
        self::assertCount(2, $events);
        self::assertFalse($events[0]->isSite());
        self::assertSame('www.acme-guide.example', $events[0]->signature()['authority'] ?? null);

        $this->runCommand('gt:presence:flush', ['--force' => true]);
        $wire = $this->transport()->envelopes()[0]['events'];
        self::assertSame('"https://www.meta.com"', $wire[0]['signature']['agent']);
        self::assertSame('sig1=:dGVzdA==:', $wire[0]['signature']['value']);
        self::assertArrayNotHasKey('signature', $wire[1]);
    }

    public function testAnApplicationPageNeverTriggersTheFlush(): void
    {
        $client = $this->bootClient();
        $client->request('GET', '/interior', server: ['HTTP_USER_AGENT' => self::GPTBOT]);

        self::assertSame([], $this->transport()->sent);
        self::assertSame(1, $this->buffer()->count());
    }
}
