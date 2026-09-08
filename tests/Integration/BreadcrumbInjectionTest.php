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

        self::assertStringContainsString(
            '<link rel="alternate" type="application/ld+json" href="https://demo.globetrotters.ai/schema.json">',
            $content,
        );
        self::assertStringContainsString('<link rel="mcp" href="https://demo.globetrotters.ai/.well-known/mcp.json">', $content);
        self::assertStringContainsString('<a href="https://demo.globetrotters.ai">AI presence for Demo</a>', $content);
        // Head half in the head, visible anchor at the end of the body.
        self::assertMatchesRegularExpression('~agent-card\.json">\n</head>~', $content);
        self::assertMatchesRegularExpression('~</a>\n</body>~', $content);
    }

    /**
     * Both lanes at once: the breadcrumb is added, and the JSON-LD the apex
     * already served is still there. The two subscribers must not displace
     * each other.
     */
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
        $client = $this->bootClient();
        $this->refreshWith(self::AI_JSON);

        $client->request('GET', '/');
        self::assertStringContainsString('https://demo.globetrotters.ai', (string) $client->getResponse()->getContent());

        $this->refreshWith(self::AI_JSON_CUSTOM);

        $client->request('GET', '/');
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<a href="https://ai.demo-tourisme.test">', $content);
        self::assertStringNotContainsString('href="https://demo.globetrotters.ai"', $content);
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
        self::assertStringContainsString('<a href="https://demo.globetrotters.ai">', $link);
    }
}
