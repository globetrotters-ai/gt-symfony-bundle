<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Serving\RobotsFilter;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel;

/**
 * Kernel variant without an app robots.txt route: the catch-all throws a 404
 * and the bundle serves a generated robots.txt.
 */
final class RobotsFallbackTest extends IntegrationTestCase
{
    public function testServesGeneratedRobotsWhenAppHasNone(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', '/robots.txt');
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        $content = (string) $response->getContent();
        self::assertStringStartsWith(RobotsFilter::MARKER, $content);
        self::assertStringContainsString(
            "User-agent: GPTBot\nAllow: /\nContent-Signal: search=yes, ai-input=yes, ai-train=yes\n",
            $content,
        );
        // CCBot is the training-corpus name customers ask about; it reached
        // the GT-hosted lane only with the registry.
        self::assertStringContainsString("User-agent: CCBot\nAllow: /", $content);
        // Generated, not decorated — but still no wildcard group, so both
        // lanes emit one canonical block.
        self::assertStringNotContainsString('User-agent: *', $content);
        // This host's own sitemap, not the Globetrotters origin's.
        self::assertStringContainsString('Sitemap: http://localhost/ai-sitemap.xml', $content);
        self::assertStringNotContainsString(TestKernel::WEBSITE_URL, $content);
    }

    /**
     * The generated response passes through kernel.response afterwards, where
     * this subscriber sees it again — on a HEAD its body has been emptied by
     * then, so the marker check cannot save it and the guard has to.
     */
    public function testHeadCarriesNoBody(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('HEAD', '/robots.txt');
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    public function testStays404WhenNoBundleCached(): void
    {
        $client = $this->bootClient();

        $client->request('GET', '/robots.txt');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }
}
