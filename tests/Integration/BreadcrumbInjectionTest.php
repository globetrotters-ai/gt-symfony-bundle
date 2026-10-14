<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Client\FetchResult;
use Globetrotters\AiPresenceBundle\Serving\ContentTypes;
use Twig\Environment;

/**
 * The ``subdomain_breadcrumb`` profile end to end: a real kernel, a real
 * refresh, and the breadcrumb in the served homepage HTML.
 */
final class BreadcrumbInjectionTest extends IntegrationTestCase
{
    protected static bool $withBreadcrumb = true;

    /** A published ai.json, whose relatedFiles sit on the canonical GT origin. */
    private const AI_JSON = '{"name":"Demo","url":"https://www.demo-tourisme.test","metadata":{"relatedFiles":['
        .'{"url":"https://demo.globetrotters.ai/llms.txt","type":"text/plain"},'
        .'{"url":"https://demo.globetrotters.ai/schema.json","type":"application/ld+json"}'
        .']}}';

    /** The same tenant after a custom hostname activates. */
    private const AI_JSON_CUSTOM = '{"name":"Demo","url":"https://www.demo-tourisme.test","metadata":{"relatedFiles":['
        .'{"url":"https://ai.demo-tourisme.test/llms.txt","type":"text/plain"},'
        .'{"url":"https://ai.demo-tourisme.test/schema.json","type":"application/ld+json"}'
        .']}}';

    private function refreshWith(string $aiJson): void
    {
        $this->serveRequiredFiles();
        $this->fetcher()->on('/ai.json', FetchResult::http(200, $aiJson));
        $this->refresh();
    }

    public function testBreadcrumbIsInTheRawHomepageHtml(): void
    {
        $client = $this->bootClient();
        $this->refreshWith(self::AI_JSON);

        $client->request('GET', '/');
        $content = (string) $client->getResponse()->getContent();

        // Every relation names a file this install serves, so each resolves to
        // this apex rather than to the Globetrotters host.
        self::assertStringContainsString(
            '<link rel="alternate" type="application/ld+json" href="/schema.json">',
            $content,
        );
        self::assertStringContainsString('<link rel="mcp" href="/.well-known/mcp.json">', $content);
        self::assertStringContainsString('<link rel="ai-catalog" href="/.well-known/ai-catalog.json">', $content);
        self::assertStringNotContainsString('demo.globetrotters.ai', $content);
        // Head half only. Installing the bundle changes nothing a visitor sees,
        // so no anchor is injected into a layout this bundle does not own.
        self::assertMatchesRegularExpression('~agent-card\.json">\n</head>~', $content);
        self::assertStringNotContainsString('<a href=', $content);
    }

    /**
     * Both lanes at once: the breadcrumb is added, and the JSON-LD the apex
     * already served is still there. The two subscribers must not displace
     * each other.
     */
    /**
     * A bundle cached by 0.6.x holds no ai-catalog.json until the upgrade's
     * first successful refresh, and Router 404s it meanwhile: the relation keeps
     * naming the Globetrotters copy until the local one is cached. Both the
     * automatic injection and the Twig helper go through the same renderer.
     */
    public function testALegacyCacheKeepsTheRemoteCatalog(): void
    {
        $client = $this->bootClient();
        $files = ['ai.json' => self::AI_JSON] + static::BODIES;
        unset($files['.well-known/ai-catalog.json']);
        static::getContainer()->get(\Globetrotters\AiPresenceBundle\Cache\ArtefactCache::class)
            ->store($files, '2026-10-01-000000', time());

        $client->request('GET', '/');
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<link rel="ai-catalog" href="https://demo.globetrotters.ai/.well-known/ai-catalog.json">', $content);
        self::assertStringContainsString('<link rel="mcp" href="/.well-known/mcp.json">', $content);

        $twig = static::getContainer()->get('twig');
        \assert($twig instanceof Environment);
        self::assertStringContainsString(
            'href="https://demo.globetrotters.ai/.well-known/ai-catalog.json"',
            $twig->createTemplate('{{ gt_ai_presence_breadcrumb_head() }}')->render(),
        );
    }

    public function testJsonLdInjectionStillHappensAlongsideIt(): void
    {
        $client = $this->bootClient();
        $this->refreshWith(self::AI_JSON);

        $client->request('GET', '/');
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('<script type="application/ld+json">', $content);
        self::assertStringContainsString('TouristDestination', $content);
        self::assertStringContainsString('rel="agent-card"', $content);
    }

    /**
     * The profile changes the breadcrumb, not the footprint: the same six paths
     * are served locally either way, because none of them is on the backend's
     * offload list.
     */
    public function testTheLocallyServedPathsAreUnchanged(): void
    {
        $client = $this->bootClient();
        $this->refreshWith(self::AI_JSON);

        foreach (ContentTypes::paths() as $path) {
            $client->request('GET', '/'.$path);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $path.' should still be served locally');
        }
    }

    /**
     * The reason the origin is derived rather than configured: it has to follow
     * a custom-hostname activation on the next ordinary refresh, with no
     * configuration change and no redeploy.
     */
    public function testTheOriginFollowsACustomHostnameActivation(): void
    {
        $this->bootClient();
        $twig = static::getContainer()->get('twig');
        \assert($twig instanceof Environment);
        $this->refreshWith(self::AI_JSON);

        // Every <link> relation is local since 0.7.0; the footer anchor is
        // where the derived origin still shows.
        self::assertStringContainsString('https://demo.globetrotters.ai', $twig->createTemplate('{{ gt_ai_presence_breadcrumb_link() }}')->render());

        $this->refreshWith(self::AI_JSON_CUSTOM);

        $link = $twig->createTemplate('{{ gt_ai_presence_breadcrumb_link() }}')->render();
        self::assertStringContainsString('https://ai.demo-tourisme.test', $link);
        self::assertStringNotContainsString('demo.globetrotters.ai', $link);
    }

    /**
     * The whole point of the site-wide scope: an agent that arrives on a deep
     * page still finds the pointers. Only the destination-scoped alternate is
     * withheld there.
     */
    public function testInteriorPagesCarryTheDiscoveryRelations(): void
    {
        $client = $this->bootClient();
        $this->refreshWith(self::AI_JSON);

        $client->request('GET', '/interior');
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('<link rel="mcp" href="/.well-known/mcp.json">', $content);
        self::assertStringContainsString('<link rel="agent-card" href="/.well-known/agent-card.json">', $content);
        self::assertStringNotContainsString('rel="alternate"', $content);
        // The JSON-LD document itself stays homepage-only.
        self::assertStringNotContainsString('<script type="application/ld+json">', $content);
    }

    /**
     * Rewriting a body used to cost the page its conditional GETs. The tag is
     * recomputed from the injected bytes and revalidated here, so a client
     * holding what we actually served still gets a 304 — through a real kernel,
     * with the application publishing its own tag.
     */
    public function testConditionalGetSurvivesTheInjection(): void
    {
        $client = $this->bootClient();
        $this->refreshWith(self::AI_JSON);

        $client->request('GET', '/etagged');
        $first = $client->getResponse();
        $etag = (string) $first->headers->get('ETag');

        self::assertSame(200, $first->getStatusCode());
        self::assertStringContainsString('rel="mcp"', (string) $first->getContent());
        // Not the application's own tag: that one described the pre-injection body.
        self::assertNotSame('"app-representation-v1"', $etag);
        self::assertSame('"'.hash('sha256', (string) $first->getContent()).'"', $etag);

        $client->request('GET', '/etagged', server: ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertSame(304, $client->getResponse()->getStatusCode());
    }

    public function testNoBreadcrumbWhenTheCacheIsCold(): void
    {
        $client = $this->bootClient();

        $client->request('GET', '/');

        self::assertStringNotContainsString('rel="agent-card"', (string) $client->getResponse()->getContent());
    }

    public function testTheInjectedHomepageCarriesNoStaleBodyMetadata(): void
    {
        $client = $this->bootClient();
        $this->refreshWith(self::AI_JSON);

        $client->request('GET', '/');
        $response = $client->getResponse();

        self::assertFalse($response->headers->has('ETag'));
        self::assertStringContainsString('rel="agent-card"', (string) $response->getContent());
    }

    public function testTwigFunctionsRenderBothHalves(): void
    {
        $this->bootClient();
        $this->refreshWith(self::AI_JSON);

        $twig = static::getContainer()->get('twig');
        \assert($twig instanceof Environment);

        $head = $twig->createTemplate('{{ gt_ai_presence_breadcrumb_head() }}')->render();
        $link = $twig->createTemplate('{{ gt_ai_presence_breadcrumb_link() }}')->render();

        self::assertStringContainsString('rel="agent-card"', $head);
        // The visible anchor is available to place by hand, never injected.
        self::assertStringContainsString('<a href="https://demo.globetrotters.ai">', $link);
    }
}
