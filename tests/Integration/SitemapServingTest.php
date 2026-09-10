<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Serving\Router;
use Globetrotters\AiPresenceBundle\Serving\Sitemap;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel;

/**
 * The generated sitemap is answered at its own path, so the application's
 * /sitemap.xml — served, redirected or absent — is never touched.
 */
final class SitemapServingTest extends IntegrationTestCase
{
    protected static bool $withSitemapRoute = true;

    public function testServesTheGeneratedSitemapAtItsOwnPath(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', 'http://apex.example/ai-sitemap.xml');
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
     * The decision this path expresses: the site's own /sitemap.xml is left to
     * the application, whatever it does with it.
     */
    public function testLeavesTheApplicationsOwnSitemapAlone(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', 'http://apex.example/sitemap.xml');
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(\Globetrotters\AiPresenceBundle\Tests\Fixtures\AntagonistController::APP_SITEMAP_XML, $response->getContent());
    }

    /**
     * Not agent traffic: a crawler reading a sitemap to schedule a fetch is not
     * an agent fetching the presence, so it must not inflate Presence
     * Analytics. The no-store headers are still re-asserted.
     */
    public function testIsNotCountedAsAgentTrafficButKeepsItsHeaders(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', 'http://apex.example/ai-sitemap.xml');
        $request = $client->getRequest();
        $response = $client->getResponse();

        self::assertFalse($request->attributes->has(Router::ATTRIBUTE_PATH));
        self::assertTrue($request->attributes->get(Router::ATTRIBUTE_SITEMAP));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('no-store', $response->headers->get('Surrogate-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        // Fetched server-side by a crawler, so the CORS grant stays scoped to
        // the discovery documents that need it.
        self::assertFalse($response->headers->has('Access-Control-Allow-Origin'));
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

        $client->request('GET', 'http://apex.example/ai-sitemap.xml');
        preg_match_all('#<loc>http://apex\.example(/[^<]*)</loc>#', (string) $client->getResponse()->getContent(), $matches);

        self::assertNotEmpty($matches[1]);
        foreach ($matches[1] as $path) {
            if ('/' === $path) {
                continue;
            }
            $client->request('GET', 'http://apex.example'.$path);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $path.' is listed but not served');
        }
    }

    /**
     * A urlset naming only the homepage says less than whatever the site
     * already serves, and an install that has never synced should look
     * untouched — matching RobotsFilter, whose Sitemap: line names this path.
     */
    public function testNotAnsweredWhenNoBundleCached(): void
    {
        $client = $this->bootClient();

        $client->request('GET', '/ai-sitemap.xml');

        // The kernel's catch-all answers instead — the bundle never claimed it.
        self::assertStringNotContainsString('<urlset', (string) $client->getResponse()->getContent());
    }
}
