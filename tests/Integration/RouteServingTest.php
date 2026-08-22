<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Serving\ContentTypes;
use Symfony\Component\HttpFoundation\Request;

final class RouteServingTest extends IntegrationTestCase
{
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
