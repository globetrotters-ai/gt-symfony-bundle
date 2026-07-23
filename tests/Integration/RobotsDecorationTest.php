<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Serving\RobotsFilter;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel;

/**
 * Kernel variant where the app serves its own robots.txt: the bundle
 * decorates that response instead of racing it.
 */
final class RobotsDecorationTest extends IntegrationTestCase
{
    protected static bool $withRobotsRoute = true;

    public function testDecoratesTheAppRobots(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', '/robots.txt');
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        $content = (string) $response->getContent();
        self::assertStringStartsWith("User-agent: *\nDisallow: /admin\n\n".RobotsFilter::MARKER, $content);
        self::assertStringContainsString('Sitemap: '.TestKernel::WEBSITE_URL.'/sitemap.xml', $content);
        self::assertSame(1, substr_count($content, RobotsFilter::MARKER));
    }

    public function testAppRobotsUntouchedWhenNoBundleCached(): void
    {
        $client = $this->bootClient();

        $client->request('GET', '/robots.txt');

        self::assertSame("User-agent: *\nDisallow: /admin\n", $client->getResponse()->getContent());
    }
}
