<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Serving\Sitemap;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel;

/**
 * Kernel variant without an app sitemap route: the catch-all throws a 404 and
 * the bundle serves a locally generated one, in the request's own URL space.
 */
final class SitemapServingTest extends IntegrationTestCase
{
    public function testServesAGeneratedSitemapWhenTheAppHasNone(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', 'http://apex.example/sitemap.xml');
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(Sitemap::CONTENT_TYPE, $response->headers->get('Content-Type'));

        $content = (string) $response->getContent();
        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $content);
        foreach (['/', '/llms.txt', '/ai.json', '/schema.json', '/.well-known/mcp.json', '/.well-known/agent-card.json'] as $path) {
            self::assertStringContainsString('<loc>http://apex.example'.$path.'</loc>', $content);
        }

        // Every URL is in this host's space, never the Globetrotters origin's,
        // and the version marker is machinery rather than content.
        self::assertStringNotContainsString(TestKernel::WEBSITE_URL, $content);
        self::assertStringNotContainsString('globetrotters-apex-version.json', $content);
    }

    /**
     * The invariant local generation buys: a sitemap can only ever list paths
     * this very install would serve.
     */
    public function testListsOnlyWhatIsActuallyServed(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', 'http://apex.example/sitemap.xml');
        preg_match_all('#<loc>http://apex\.example(/[^<]*)</loc>#', (string) $client->getResponse()->getContent(), $matches);

        foreach ($matches[1] as $path) {
            if ('/' === $path) {
                continue;
            }
            $client->request('GET', 'http://apex.example'.$path);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $path.' is listed but not served');
        }
    }

    public function testStays404WhenNoBundleCached(): void
    {
        $client = $this->bootClient();

        $client->request('GET', '/sitemap.xml');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }
}
