<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Serving\Sitemap;

/**
 * Kernel variant where the app serves its own sitemap — the common case at a
 * real apex. The bundle merges its discovery URLs into that document rather
 * than standing aside, because the readiness probe reads a hardcoded
 * /sitemap.xml and nothing else.
 */
final class SitemapDecorationTest extends IntegrationTestCase
{
    protected static bool $withSitemapRoute = true;

    public function testMergesTheDiscoveryUrlsIntoTheAppSitemap(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', 'http://apex.example/sitemap.xml');
        $response = $client->getResponse();
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        // The app's own entry is untouched.
        self::assertStringContainsString('<loc>https://app.example/about</loc>', $content);
        // ... and llms.txt is now listed, which is what the probe reads.
        self::assertStringContainsString('<loc>http://apex.example/llms.txt</loc>', $content);
        self::assertStringEndsWith('</urlset>', $content);
        self::assertSame(1, substr_count($content, Sitemap::MARKER));

        // Still parseable XML with the app's own root element.
        $xml = simplexml_load_string($content);
        self::assertNotFalse($xml);
        self::assertSame('urlset', $xml->getName());
    }

    public function testAppSitemapUntouchedWhenNoBundleCached(): void
    {
        $client = $this->bootClient();

        $client->request('GET', 'http://apex.example/sitemap.xml');

        self::assertStringNotContainsString(Sitemap::MARKER, (string) $client->getResponse()->getContent());
    }

    public function testRobotsNamesThisHostsSitemap(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', 'http://apex.example/robots.txt');

        self::assertStringContainsString(
            'Sitemap: http://apex.example/sitemap.xml',
            (string) $client->getResponse()->getContent(),
        );
    }
}
