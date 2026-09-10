<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Serving\ContentTypes;
use Globetrotters\AiPresenceBundle\Serving\Sitemap;
use Globetrotters\AiPresenceBundle\Settings\Options;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class SitemapTest extends TestCase
{
    private const BASE_URL = 'https://nantes.globetrotters.ai';

    /**
     * @param array<string, string> $files
     */
    private function sitemap(array $files = [], string $homepagePath = '/', int $changedAt = 0, int $refreshedAt = 0): Sitemap
    {
        $pool = new ArrayAdapter();
        $cache = new ArtefactCache($pool);
        if ([] !== $files) {
            $cache->store($files, 'v1', 0);
        }
        $options = new Options($pool, self::BASE_URL, 'daily', $homepagePath);
        $options->updateState(['content_changed_at' => $changedAt, 'last_refresh' => $refreshedAt]);

        return new Sitemap($options, $cache);
    }

    /**
     * @return array<string, string>
     */
    private function fullSet(): array
    {
        $files = [];
        foreach (ContentTypes::paths() as $path) {
            $files[$path] = 'body';
        }

        return $files;
    }

    public function testListsTheHomepageAndEveryCachedArtefact(): void
    {
        self::assertSame([
            '/',
            '/llms.txt',
            '/ai.json',
            '/schema.json',
            '/.well-known/mcp.json',
            '/.well-known/agent-card.json',
        ], $this->sitemap($this->fullSet())->paths());
    }

    /**
     * Sync machinery, not content — and the backend's own apex sitemap does
     * not list it either.
     */
    public function testNeverListsTheVersionMarker(): void
    {
        self::assertNotContains('/'.ContentTypes::VERSION_MARKER, $this->sitemap($this->fullSet())->paths());
    }

    /**
     * The invariant the local generation buys: a listing can never name a path
     * this bundle would not serve, because it is built from the same cache the
     * request would be served from.
     */
    public function testOmitsArtefactsThatAreNotCached(): void
    {
        $paths = $this->sitemap(['llms.txt' => 'body'])->paths();

        self::assertSame(['/', '/llms.txt'], $paths);
    }

    public function testUsesTheConfiguredHomepagePath(): void
    {
        self::assertSame('/en', $this->sitemap(['llms.txt' => 'body'], homepagePath: '/en')->paths()[0]);
    }

    public function testRendersAUrlsetInTheRequestOrigin(): void
    {
        $xml = $this->sitemap(['llms.txt' => 'body'], changedAt: 1757376000)->render('https://apex.example');

        self::assertSame(
            '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .Sitemap::MARKER."\n"
            ."  <url>\n    <loc>https://apex.example/</loc>\n    <lastmod>2025-09-09</lastmod>\n  </url>\n"
            ."  <url>\n    <loc>https://apex.example/llms.txt</loc>\n    <lastmod>2025-09-09</lastmod>\n  </url>\n"
            .'</urlset>'."\n",
            $xml,
        );
    }

    public function testNeverNamesTheGlobetrottersOrigin(): void
    {
        self::assertStringNotContainsString(
            self::BASE_URL,
            $this->sitemap($this->fullSet())->render('https://apex.example'),
        );
    }

    public function testTrimsATrailingSlashFromTheOrigin(): void
    {
        self::assertStringContainsString(
            '<loc>https://apex.example/llms.txt</loc>',
            $this->sitemap(['llms.txt' => 'body'])->render('https://apex.example/'),
        );
    }

    /**
     * Refreshes run daily whether or not anything moved; stamping every URL
     * with the refresh date would claim a freshness the content does not have.
     */
    public function testLastModIsTheContentChangeDateNotTheRefreshDate(): void
    {
        $xml = $this->sitemap(['llms.txt' => 'body'], changedAt: 1757376000, refreshedAt: 1788912000)->render('https://apex.example');

        self::assertStringContainsString('<lastmod>2025-09-09</lastmod>', $xml);
        self::assertStringNotContainsString('2026-09-09', $xml);
    }

    /**
     * Installs that predate the state key have no change date; one overstated
     * stamp beats no freshness signal at all.
     */
    public function testFallsBackToTheRefreshDateWhenNoChangeDateIsStored(): void
    {
        $xml = $this->sitemap(['llms.txt' => 'body'], refreshedAt: 1757376000)->render('https://apex.example');

        self::assertStringContainsString('<lastmod>2025-09-09</lastmod>', $xml);
    }

    public function testOmitsLastModWhenNothingHasEverBeenRefreshed(): void
    {
        $xml = $this->sitemap(['llms.txt' => 'body'])->render('https://apex.example');

        self::assertStringNotContainsString('<lastmod>', $xml);
        self::assertStringContainsString("  <url>\n    <loc>https://apex.example/</loc>\n  </url>\n", $xml);
    }

    public function testDecorateInsertsEntriesBeforeTheClosingTag(): void
    {
        $app = "<?xml version=\"1.0\"?>\n<urlset>\n  <url><loc>https://apex.example/about</loc></url>\n</urlset>\n";
        $decorated = $this->sitemap(['llms.txt' => 'body'])->decorate($app, 'https://apex.example');

        self::assertNotNull($decorated);
        self::assertStringContainsString('<loc>https://apex.example/about</loc>', $decorated);
        self::assertStringContainsString('<loc>https://apex.example/llms.txt</loc>', $decorated);
        self::assertStringEndsWith("</urlset>\n", $decorated);
        self::assertLessThan(
            strpos($decorated, '</urlset>'),
            strpos($decorated, '<loc>https://apex.example/llms.txt</loc>'),
        );
    }

    /**
     * Never duplicate a URL the application already published.
     */
    public function testDecorateSkipsLocsTheDocumentAlreadyCarries(): void
    {
        $app = "<urlset>\n  <url><loc>https://apex.example/llms.txt</loc></url>\n</urlset>";
        $decorated = $this->sitemap(['llms.txt' => 'body'])->decorate($app, 'https://apex.example');

        self::assertNotNull($decorated);
        self::assertSame(1, substr_count($decorated, '<loc>https://apex.example/llms.txt</loc>'));
    }

    public function testDecorateReturnsNullWhenEveryUrlIsAlreadyListed(): void
    {
        $app = "<urlset>\n  <url><loc>https://apex.example/</loc></url>\n  <url><loc>https://apex.example/llms.txt</loc></url>\n</urlset>";

        self::assertNull($this->sitemap(['llms.txt' => 'body'])->decorate($app, 'https://apex.example'));
    }

    public function testDecorateRefusesASitemapIndex(): void
    {
        $app = '<sitemapindex><sitemap><loc>https://apex.example/s1.xml</loc></sitemap></sitemapindex>';

        self::assertNull($this->sitemap(['llms.txt' => 'body'])->decorate($app, 'https://apex.example'));
    }

    public function testDecorateRefusesAnAlreadyDecoratedDocument(): void
    {
        $once = $this->sitemap(['llms.txt' => 'body'])->decorate('<urlset></urlset>', 'https://apex.example');

        self::assertNotNull($once);
        self::assertNull($this->sitemap(['llms.txt' => 'body'])->decorate($once, 'https://apex.example'));
    }

    public function testDecorateRefusesAnOversizedDocument(): void
    {
        $app = '<urlset>'.str_repeat('<url><loc>https://apex.example/x</loc></url>', 30000).'</urlset>';

        self::assertGreaterThan(1048576, \strlen($app));
        self::assertNull($this->sitemap(['llms.txt' => 'body'])->decorate($app, 'https://apex.example'));
    }

    public function testEscapesTheOrigin(): void
    {
        $xml = $this->sitemap(['llms.txt' => 'body'])->render('https://apex.example/?a=1&b=2');

        self::assertStringNotContainsString('&b=', $xml);
        self::assertStringContainsString('&amp;b=2', $xml);
    }
}
