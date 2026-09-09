<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Client\FetchResult;
use Globetrotters\AiPresenceBundle\Serving\ContentTypes;
use Symfony\Component\HttpFoundation\Request;

final class RouteServingTest extends IntegrationTestCase
{
    /**
     * A key shaped like the ones the presence stack issues (32 hex chars).
     */
    private const INDEXNOW_KEY = 'e715a2e7bf3c4a1d8e0b6f9c2d5a7e14';

    public function testServesAllArtefactsOverTheCatchAllAntagonist(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        self::assertSame(0, $this->refresh()->getStatusCode());

        foreach (ContentTypes::paths() as $path) {
            $client->request('GET', '/'.$path);
            $response = $client->getResponse();

            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertSame(ContentTypes::forPath($path), $response->headers->get('Content-Type'), $path);
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $path);
            self::assertSame('no-store, private', $response->headers->get('Cache-Control'), $path);
            self::assertSame('no-store', $response->headers->get('Surrogate-Control'), $path);
            self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'), $path);
            self::assertNotSame('ANTAGONIST', $response->getContent(), $path);

            if (ContentTypes::VERSION_MARKER !== $path) {
                self::assertSame(static::BODIES[$path], $response->getContent(), $path);
            }
        }
    }

    public function testColdCacheFallsThroughToTheApp(): void
    {
        $client = $this->bootClient();

        $client->request('GET', '/llms.txt');

        self::assertSame('ANTAGONIST', $client->getResponse()->getContent());
    }

    public function testTrustedHostValidationRunsBeforeArtefactRouting(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();
        Request::setTrustedHosts(['^allowed\\.test$']);

        try {
            $client->request('GET', '/llms.txt', server: ['HTTP_HOST' => 'evil.test']);

            self::assertSame(400, $client->getResponse()->getStatusCode());
            self::assertNotSame('llms body', $client->getResponse()->getContent());
        } finally {
            Request::setTrustedHosts([]);
        }
    }

    public function testUnknownPathsStayWithTheApp(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', '/llms-full.txt');

        self::assertSame('ANTAGONIST', $client->getResponse()->getContent());
    }

    public function testTheIndexNowKeyFromTheMarkerIsServedAtItsOwnPath(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->serveMarkerWithKey(self::INDEXNOW_KEY);
        $this->refresh();

        $client->request('GET', '/'.self::INDEXNOW_KEY.'.txt');
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        // Byte-equal to the key: IndexNow compares the file's contents to the
        // key it was handed, so a trailing newline would fail verification.
        self::assertSame(self::INDEXNOW_KEY, $response->getContent());
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('no-store', $response->headers->get('Surrogate-Control'));
        // The apex of a site we do not own: the CORS grant stays scoped to the
        // discovery documents a browser-context agent client has to read.
        self::assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    public function testAKeylessRefreshLeavesTheKeyPathWithTheApp(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        // No marker upstream → synthesized, carrying no key. This is what a
        // keyless environment looks like, and the refresh must still succeed.
        self::assertSame(0, $this->refresh()->getStatusCode());

        $client->request('GET', '/'.self::INDEXNOW_KEY.'.txt');

        self::assertSame('ANTAGONIST', $client->getResponse()->getContent());
    }

    public function testARefreshWithoutTheKeyStopsServingAPreviouslyLearnedOne(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->serveMarkerWithKey(self::INDEXNOW_KEY);
        $this->refresh();

        // The key is withdrawn upstream; the next refresh must stop serving it
        // rather than keep a copy that no longer verifies anything.
        $this->fetcher()->on(
            '/'.ContentTypes::VERSION_MARKER,
            FetchResult::http(200, '{"version":"2026-06-02-120000"}'),
        );
        $this->refresh();

        $client->request('GET', '/'.self::INDEXNOW_KEY.'.txt');

        self::assertSame('ANTAGONIST', $client->getResponse()->getContent());
    }

    public function testTheArtefactStillWinsForLlmsTxtWithAKeyStored(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->serveMarkerWithKey(self::INDEXNOW_KEY);
        $this->refresh();

        $client->request('GET', '/llms.txt');

        self::assertSame(self::BODIES['llms.txt'], $client->getResponse()->getContent());
        self::assertSame('text/plain; charset=utf-8', $client->getResponse()->headers->get('Content-Type'));
        self::assertSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * Serve an upstream marker carrying an IndexNow key, shaped as the backend
     * renders it (``render_version_marker``).
     */
    private function serveMarkerWithKey(string $key): void
    {
        $this->fetcher()->on(
            '/'.ContentTypes::VERSION_MARKER,
            FetchResult::http(200, (string) json_encode([
                'version' => '2026-06-01-120000',
                'contentHash' => 'abc',
                'indexnowKey' => $key,
            ])),
        );
    }

    public function testSynthesizedVersionMarkerIsServed(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        // FakeFetcher's 404 fallback covers the marker fetch → synthesized.
        $this->refresh();

        $client->request('GET', '/.well-known/globetrotters-apex-version.json');

        $marker = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($marker);
        self::assertSame('globetrotters-apex-symfony-bundle', $marker['generator']);
        self::assertSame('synthesized', $marker['source']);
        self::assertSame('demo', $marker['destinationSlug']);
    }
}
