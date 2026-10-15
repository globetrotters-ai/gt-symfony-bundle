<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Analytics\Event;
use Globetrotters\AiPresenceBundle\Serving\ApiCatalog;
use Globetrotters\AiPresenceBundle\Serving\Router;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel;

/**
 * ``/.well-known/api-catalog`` (RFC 9727): generated from the cache on every
 * request, anchored on the request's own origin, and counted as agent traffic.
 */
final class ApiCatalogServingTest extends IntegrationTestCase
{
    protected static bool $withReporting = true;

    public function testServesALinksetAnchoredOnTheRequestHost(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', 'http://apex.example/.well-known/api-catalog');
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(ApiCatalog::CONTENT_TYPE, $response->headers->get('Content-Type'));
        $linkset = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($linkset);
        self::assertSame('http://apex.example/', $linkset['linkset'][0]['anchor']);
        self::assertSame('http://apex.example/llms.txt', $linkset['linkset'][0]['service-doc'][0]['href']);
        self::assertCount(3, $linkset['linkset'][0]['service-desc']);
        self::assertStringNotContainsString(TestKernel::WEBSITE_URL, (string) $response->getContent());
    }

    public function testCarriesTheArtefactHeaders(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', '/.well-known/api-catalog');
        $headers = $client->getResponse()->headers;

        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('no-store, private', $headers->get('Cache-Control'));
        self::assertSame('no-store', $headers->get('Surrogate-Control'));
        // A discovery document browser-context agents read, granted like the
        // artefacts it lists.
        self::assertSame('*', $headers->get('Access-Control-Allow-Origin'));
    }

    public function testEveryListedTargetIsServed(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', 'http://apex.example/.well-known/api-catalog');
        $linkset = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($linkset);
        $hrefs = array_column([...$linkset['linkset'][0]['service-doc'], ...$linkset['linkset'][0]['service-desc']], 'href');

        self::assertCount(4, $hrefs);
        foreach ($hrefs as $href) {
            $client->request('GET', $href);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $href.' is listed but not served');
        }
    }

    public function testIsCountedAsAgentTraffic(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', '/.well-known/api-catalog', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
        ]);
        $body = (string) $client->getResponse()->getContent();

        self::assertSame('/.well-known/api-catalog', $client->getRequest()->attributes->get(Router::ATTRIBUTE_PATH));
        $events = $this->buffer()->claim(10);
        self::assertSame(['/.well-known/api-catalog'], array_map(static fn (Event $event): string => $event->path(), $events));
        self::assertSame(\strlen($body), $events[0]->bytes());
    }

    public function testIsListedInTheSitemap(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', 'http://apex.example/ai-sitemap.xml');

        self::assertStringContainsString(
            '<loc>http://apex.example/.well-known/api-catalog</loc>',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testNotAnsweredWhenNoBundleCached(): void
    {
        $client = $this->bootClient();

        $client->request('GET', '/.well-known/api-catalog');

        // The application's own answer: the bundle never claimed the path.
        self::assertNotSame(ApiCatalog::CONTENT_TYPE, $client->getResponse()->headers->get('Content-Type'));
        self::assertFalse($client->getRequest()->attributes->has(Router::ATTRIBUTE_PATH));
    }

    public function testOnlyGetAndHeadAreAnswered(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('HEAD', '/.well-known/api-catalog');
        self::assertSame(ApiCatalog::CONTENT_TYPE, $client->getResponse()->headers->get('Content-Type'));

        $client->request('POST', '/.well-known/api-catalog');
        self::assertNotSame(ApiCatalog::CONTENT_TYPE, $client->getResponse()->headers->get('Content-Type'));
    }
}
