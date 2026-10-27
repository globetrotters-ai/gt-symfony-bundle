<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Serving\LinkHeader;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\AntagonistController;

/**
 * The homepage ``Link`` header: the registered ``api-catalog`` and
 * ``service-doc`` relations, then the HTTP twin of the injected
 * ``<link rel="mcp|agent-card|ai-catalog">`` tags, on a 200 HTML response for
 * the homepage only, added to any ``Link`` header the application sends.
 */
final class HomepageLinkHeaderTest extends IntegrationTestCase
{
    private const LINK = '</.well-known/api-catalog>; rel="api-catalog", </llms.txt>; rel="service-doc", </.well-known/mcp.json>; rel="mcp", </.well-known/agent-card.json>; rel="agent-card", </.well-known/ai-catalog.json>; rel="ai-catalog"';

    public function testTheHomepageCarriesTheHeader(): void
    {
        $client = $this->cachedClient();

        $client->request('GET', '/');

        self::assertSame([self::LINK], $client->getResponse()->headers->all('link'));
    }

    public function testItIsAddedToTheApplicationsOwnLinkHeader(): void
    {
        $client = $this->cachedClient();

        $client->request('GET', '/?'.AntagonistController::APP_LINK_QUERY.'=1');

        self::assertSame(
            [AntagonistController::APP_LINK, self::LINK],
            $client->getResponse()->headers->all('link'),
        );
    }

    public function testAnInteriorPageHasNone(): void
    {
        $client = $this->cachedClient();

        $client->request('GET', '/interior');

        self::assertFalse($client->getResponse()->headers->has('Link'));
    }

    public function testANonHtmlHomepageHasNone(): void
    {
        $client = $this->cachedClient();

        $client->request('GET', '/?'.AntagonistController::JSON_QUERY.'=1');

        self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
        self::assertFalse($client->getResponse()->headers->has('Link'));
    }

    public function testAColdCacheAdvertisesNothing(): void
    {
        $client = $this->bootClient();

        $client->request('GET', '/');

        self::assertFalse($client->getResponse()->headers->has('Link'));
    }

    public function testOnlyCachedTargetsAreNamed(): void
    {
        $client = $this->bootClient();
        $files = static::BODIES;
        unset($files['.well-known/ai-catalog.json']);
        static::getContainer()->get(\Globetrotters\AiPresenceBundle\Cache\ArtefactCache::class)
            ->store($files, '2026-10-01-000000', time());

        $client->request('GET', '/');

        self::assertSame(
            ['</.well-known/api-catalog>; rel="api-catalog", </llms.txt>; rel="service-doc", </.well-known/mcp.json>; rel="mcp", </.well-known/agent-card.json>; rel="agent-card"'],
            $client->getResponse()->headers->all('link'),
        );
    }

    public function testTheValueMatchesTheBackend(): void
    {
        // The backend's apex homepage link relations, in order:
        // the registered relations validators read first, ours after.
        self::assertSame([
            '.well-known/api-catalog' => 'api-catalog',
            'llms.txt' => 'service-doc',
            '.well-known/mcp.json' => 'mcp',
            '.well-known/agent-card.json' => 'agent-card',
            '.well-known/ai-catalog.json' => 'ai-catalog',
        ], LinkHeader::RELS);
    }

    private function cachedClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        return $client;
    }
}
