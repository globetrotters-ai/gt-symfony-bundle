<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Analytics\ServedVersion;
use Globetrotters\AiPresenceBundle\Client\FetchResult;
use Globetrotters\AiPresenceBundle\Settings\Options;

/**
 * The refresh stamps what it pulled, the web lane what it served, and the two
 * are compared where the CLI can see both: gt:status, and the flush envelope's
 * captureContext.servedVersion that the backend reads.
 */
final class ServedVersionTest extends IntegrationTestCase
{
    protected static bool $withReporting = true;

    private const MARKER = '/.well-known/globetrotters-apex-version.json';

    public function testTheWebRecordsTheVersionItServed(): void
    {
        $client = $this->bootClient();
        $this->pull('2026-10-18-050325');

        $client->request('GET', '/');

        $record = $this->servedVersion()->read();
        self::assertNotNull($record);
        self::assertSame('2026-10-18-050325', $record['version']);
        self::assertSame(static::$kernel?->getProjectDir(), $record['project_dir']);
    }

    public function testAnArtefactRequestRecordsItToo(): void
    {
        $client = $this->bootClient();
        $this->pull('2026-10-18-050325');

        $client->request('GET', '/llms.txt');

        self::assertSame('2026-10-18-050325', $this->servedVersion()->version());
    }

    public function testStatusAgreesWhenTheWebServesWhatWasPulled(): void
    {
        $client = $this->bootClient();
        $this->pull('2026-10-18-050325');
        $client->request('GET', '/');

        $display = $this->flat($this->runCommand('gt:status')->getDisplay());

        self::assertStringContainsString('Pulled by 2026-10-18-050325 at', $display);
        self::assertStringContainsString('Web serving 2026-10-18-050325, seen', $display);
        self::assertStringNotContainsString("don't share the cache pool", $display);
    }

    public function testStatusFlagsAWebThatKeepsServingAnOlderBundleAfterThePull(): void
    {
        $this->bootClient();
        $this->pull('2026-10-18-050325');
        $pulledAt = $this->options()->pulledBy()['at'] ?? 0;
        // A web request after the pull, reading a pool the pull never wrote.
        $this->servedVersion()->observe('2026-10-14-104728', $pulledAt + 60);

        $display = $this->flat($this->runCommand('gt:status')->getDisplay());

        self::assertStringContainsString("pulled v2026-10-18-050325 but the web serves v2026-10-14-104728: the CLI and the web server don't share the cache pool", $display);
        self::assertStringContainsString('#cache-pool', $display);
    }

    public function testAWebObservationFromBeforeThePullIsOnlyLag(): void
    {
        $this->bootClient();
        $this->pull('2026-10-18-050325');
        $pulledAt = $this->options()->pulledBy()['at'] ?? 0;
        $this->servedVersion()->observe('2026-10-14-104728', $pulledAt - 60);

        $display = $this->flat($this->runCommand('gt:status')->getDisplay());

        self::assertStringContainsString('Web serving 2026-10-14-104728', $display);
        self::assertStringNotContainsString("don't share the cache pool", $display);
    }

    public function testTheFlushEnvelopeCarriesTheServedVersion(): void
    {
        $client = $this->bootClient();
        $this->pull('2026-10-18-050325');
        $client->request('GET', '/llms.txt');

        self::assertSame(0, $this->runCommand('gt:presence:flush', ['--force' => true])->getStatusCode());

        $envelopes = $this->transport()->envelopes();
        self::assertNotSame([], $envelopes);
        self::assertSame('2026-10-18-050325', $envelopes[0]['captureContext']['servedVersion']);
    }

    public function testTheEnvelopeSaysNullBeforeTheWebHasServedAnything(): void
    {
        $this->bootClient();

        $this->runCommand('gt:presence:flush', ['--force' => true]);

        $envelopes = $this->transport()->envelopes();
        self::assertNotSame([], $envelopes);
        self::assertArrayHasKey('servedVersion', $envelopes[0]['captureContext']);
        self::assertNull($envelopes[0]['captureContext']['servedVersion']);
    }

    private function pull(string $version): void
    {
        $this->serveRequiredFiles();
        $this->fetcher()->on(self::MARKER, FetchResult::http(200, json_encode(['version' => $version], \JSON_THROW_ON_ERROR)));
        self::assertSame(0, $this->refresh()->getStatusCode());
    }

    private function servedVersion(): ServedVersion
    {
        $served = static::getContainer()->get(ServedVersion::class);
        \assert($served instanceof ServedVersion);

        return $served;
    }

    private function options(): Options
    {
        $options = static::getContainer()->get(Options::class);
        \assert($options instanceof Options);
        $options->reset();

        return $options;
    }

    private function flat(string $display): string
    {
        return preg_replace('/\s+/', ' ', $display) ?? '';
    }
}
