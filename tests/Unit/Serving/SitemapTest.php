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
        $cache = new ArtefactCache($pool, self::BASE_URL);
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
            ."  <url>\n    <loc>https://apex.example/</loc>\n  </url>\n"
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
     * A refresh date claims a freshness the content does not have, so it is
     * never a fallback: until the install has seen a change, the element is
     * simply absent.
     */
    public function testOmitsLastModUntilTheContentHasChanged(): void
    {
        $xml = $this->sitemap(['llms.txt' => 'body'], refreshedAt: 1757376000)->render('https://apex.example');

        self::assertStringNotContainsString('<lastmod>', $xml);
    }

    /**
     * The homepage is the customer's own page, edited independently of the
     * bundle; only the artefacts carry the bundle's date.
     */
    public function testNeverStampsTheHomepage(): void
    {
        $xml = $this->sitemap(['llms.txt' => 'body'], changedAt: 1757376000)->render('https://apex.example');

        self::assertStringContainsString("  <url>\n    <loc>https://apex.example/</loc>\n  </url>\n", $xml);
        self::assertStringContainsString("<loc>https://apex.example/llms.txt</loc>\n    <lastmod>2025-09-09</lastmod>", $xml);
    }

    /**
     * The gate is what the listing holds: with only the version marker cached,
     * a urlset would name nothing but the homepage.
     */
    public function testRendersNothingWhenNoArtefactIsListable(): void
    {
        self::assertSame('', $this->sitemap([ContentTypes::VERSION_MARKER => '{}'])->render('https://apex.example'));
    }

    public function testOmitsLastModWhenNothingHasEverBeenRefreshed(): void
    {
        $xml = $this->sitemap(['llms.txt' => 'body'])->render('https://apex.example');

        self::assertStringNotContainsString('<lastmod>', $xml);
        self::assertStringContainsString("  <url>\n    <loc>https://apex.example/</loc>\n  </url>\n", $xml);
    }

    public function testEscapesTheOrigin(): void
    {
        $xml = $this->sitemap(['llms.txt' => 'body'])->render('https://apex.example/?a=1&b=2');

        self::assertStringNotContainsString('&b=', $xml);
        self::assertStringContainsString('&amp;b=2', $xml);
    }
}
