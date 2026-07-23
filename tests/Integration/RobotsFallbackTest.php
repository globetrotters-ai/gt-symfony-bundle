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
        self::assertStringContainsString("User-agent: GPTBot\nAllow: /", $content);
        self::assertStringContainsString('Sitemap: '.TestKernel::WEBSITE_URL.'/sitemap.xml', $content);
    }

    public function testStays404WhenNoBundleCached(): void
    {
        $client = $this->bootClient();

        $client->request('GET', '/robots.txt');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }
}
