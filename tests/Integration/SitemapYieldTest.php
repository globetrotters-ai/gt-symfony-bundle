<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Tests\Fixtures\AntagonistController;

/**
 * Kernel variant where the app serves its own sitemap. Unlike robots.txt, the
 * bundle does not decorate it — an application's sitemap is a manifest of that
 * application's site and it is left byte-for-byte alone.
 */
final class SitemapYieldTest extends IntegrationTestCase
{
    protected static bool $withSitemapRoute = true;

    public function testLeavesTheAppSitemapAlone(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', 'http://apex.example/sitemap.xml');
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(AntagonistController::APP_SITEMAP_XML, $response->getContent());
    }

    /**
     * ... and robots.txt still names this host's sitemap, because that is the
     * app's own sitemap URL, which is exactly the point of the repoint.
     */
    public function testRobotsStillNamesThisHostsSitemap(): void
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
