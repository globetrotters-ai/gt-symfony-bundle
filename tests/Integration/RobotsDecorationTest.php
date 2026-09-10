<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Serving\RobotsFilter;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\AntagonistController;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Kernel variant where the app serves its own robots.txt — with an ETag, an
 * exact Content-Length, and a Last-Modified stamped late the way
 * CacheAttributeListener does — and the bundle decorates it instead of racing
 * it.
 */
final class RobotsDecorationTest extends IntegrationTestCase
{
    protected static bool $withRobotsRoute = true;

    public function testDecoratesTheAppRobots(): void
    {
        $client = $this->cachedClient();

        $client->request('GET', '/robots.txt');
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        $content = (string) $response->getContent();
        self::assertStringStartsWith("User-agent: *\nDisallow: /admin\n\n".RobotsFilter::MARKER, $content);
        self::assertStringContainsString("User-agent: GPTBot\nUser-agent: OAI-SearchBot\n", $content);
        // Every named agent keeps the site's /admin restriction; nothing grants
        // it more than the wildcard group did.
        self::assertStringContainsString("User-agent: cohere-ai\nDisallow: /admin\nContent-Signal: search=yes, ai-input=yes\n", $content);
        self::assertStringNotContainsString('Allow: /', $content);
        self::assertStringNotContainsString('ai-train', $content);
        self::assertStringContainsString('Sitemap: http://localhost/ai-sitemap.xml', $content);
        self::assertStringNotContainsString(TestKernel::WEBSITE_URL, $content);
        self::assertSame(1, substr_count($content, RobotsFilter::MARKER));
        // The app owns the wildcard group; appending a second one could
        // override the site's real crawl rules.
        self::assertSame(1, substr_count($content, 'User-agent: *'));
    }

    public function testASiteWideDisallowStaysSiteWide(): void
    {
        $client = $this->cachedClient();

        $client->request('GET', '/robots.txt?fixture=full');
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString("User-agent: cohere-ai\nDisallow: /\n", $content);
        self::assertStringNotContainsString('Allow:', $content);
    }

    public function testAnAgentTheSiteNamesKeepsOnlyItsOwnGroup(): void
    {
        $client = $this->cachedClient();

        $client->request('GET', '/robots.txt?fixture=named');
        $content = (string) $client->getResponse()->getContent();

        self::assertSame(1, substr_count($content, 'User-agent: GPTBot'));
        self::assertStringContainsString("User-agent: OAI-SearchBot\n", $content);
    }

    public function testAppRobotsUntouchedWhenNoBundleCached(): void
    {
        $client = $this->bootClient();

        $client->request('GET', '/robots.txt');
        $response = $client->getResponse();

        self::assertSame(AntagonistController::ROBOTS['wildcard'], $response->getContent());
        self::assertSame(AntagonistController::ROBOTS_ETAG, $response->getEtag());
        self::assertSame(AntagonistController::ROBOTS_LAST_MODIFIED, $response->headers->get('Last-Modified'));
    }

    /**
     * The reported defect: HEAD returned the application's ETag, Last-Modified
     * and Content-Length while GET returned the decorated body's. Both methods
     * must now describe the one decorated representation.
     */
    public function testHeadAndGetDescribeTheSameDecoratedRepresentation(): void
    {
        $client = $this->cachedClient();

        $client->request('GET', '/robots.txt');
        $get = $client->getResponse();
        $client->request('HEAD', '/robots.txt');
        $head = $client->getResponse();

        $body = (string) $get->getContent();
        self::assertStringContainsString(RobotsFilter::MARKER, $body);
        self::assertSame('', $head->getContent());
        self::assertSame(200, $head->getStatusCode());

        self::assertSame('"'.hash('sha256', $body).'"', $get->getEtag());
        self::assertSame($get->getEtag(), $head->getEtag());
        self::assertSame((string) \strlen($body), $head->headers->get('Content-Length'));
        self::assertNotSame(\strlen(AntagonistController::ROBOTS['wildcard']), \strlen($body));
        foreach ([$get, $head] as $response) {
            // Stamped by the late listener, and dropped after it.
            self::assertFalse($response->headers->has('Last-Modified'));
        }
        self::assertSame($get->headers->get('Content-Type'), $head->headers->get('Content-Type'));
    }

    public function testConditionalRequestsRevalidateAgainstTheDecoratedTag(): void
    {
        $client = $this->cachedClient();
        $client->request('GET', '/robots.txt');
        $etag = (string) $client->getResponse()->getEtag();

        foreach (['GET', 'HEAD'] as $method) {
            $client->request($method, '/robots.txt', server: ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertSame(304, $client->getResponse()->getStatusCode(), $method);
            self::assertSame('', $client->getResponse()->getContent());
        }

        // The application's own tag names the undecorated file, which is never
        // served while a bundle is cached.
        $client->request('GET', '/robots.txt', server: ['HTTP_IF_NONE_MATCH' => AntagonistController::ROBOTS_ETAG]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString(RobotsFilter::MARKER, (string) $client->getResponse()->getContent());
    }

    private function cachedClient(): KernelBrowser
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        return $client;
    }
}
