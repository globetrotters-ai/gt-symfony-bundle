<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Serving\Breadcrumb;
use Globetrotters\AiPresenceBundle\Serving\ContentTypes;
use PHPUnit\Framework\TestCase;

final class BreadcrumbTest extends TestCase
{
    /**
     * A published ai.json: `url` is the customer's own apex, while
     * metadata.relatedFiles carry absolute URLs on the canonical GT origin.
     */
    private const AI_JSON = <<<'JSON'
        {
          "$schema": "https://ai-json.org/schemas/v1.1.1/ai.json",
          "name": "Acme",
          "organization": {"name": "Acme", "url": "https://www.acme-tourism.example"},
          "url": "https://www.acme-tourism.example",
          "metadata": {
            "relatedFiles": [
              {"url": "https://ai.acme.example/llms.txt", "type": "text/plain"},
              {"url": "https://ai.acme.example/llms-full.txt", "type": "text/plain"},
              {"url": "https://ai.acme.example/schema.json", "type": "application/ld+json"},
              {"url": "https://ai.acme.example/content.md", "type": "text/markdown"}
            ]
          }
        }
        JSON;

    public function testOriginComesFromRelatedFiles(): void
    {
        self::assertSame('https://ai.acme.example', Breadcrumb::originFrom(self::AI_JSON));
    }

    /**
     * The regression this whole derivation exists to avoid: `url` is the
     * customer's own website, never the host we publish to.
     */
    public function testOriginIgnoresTheTopLevelUrlField(): void
    {
        $json = '{"url":"https://www.acme-tourism.example","metadata":{"relatedFiles":[{"url":"https://acme.globetrotters.ai/llms.txt"}]}}';

        self::assertSame('https://acme.globetrotters.ai', Breadcrumb::originFrom($json));
    }

    public function testOriginFollowsTheCustomHostAfterActivation(): void
    {
        $before = '{"metadata":{"relatedFiles":[{"url":"https://acme.globetrotters.ai/llms.txt"}]}}';
        $after = '{"metadata":{"relatedFiles":[{"url":"https://ai.acme.example/llms.txt"}]}}';

        self::assertSame('https://acme.globetrotters.ai', Breadcrumb::originFrom($before));
        self::assertSame('https://ai.acme.example', Breadcrumb::originFrom($after));
    }

    /**
     * llms-full.txt and content.md are the offloaded pair, so on an apex bundle
     * they can point somewhere else entirely. llms.txt / schema.json never are.
     */
    public function testOriginPrefersANeverOffloadedEntry(): void
    {
        $json = '{"metadata":{"relatedFiles":['
            .'{"url":"https://elsewhere.example/llms-full.txt"},'
            .'{"url":"https://ai.acme.example/llms.txt"}'
            .']}}';

        self::assertSame('https://ai.acme.example', Breadcrumb::originFrom($json));
    }

    public function testOriginFallsBackToSchemaJsonEntry(): void
    {
        $json = '{"metadata":{"relatedFiles":[{"url":"https://ai.acme.example/schema.json"}]}}';

        self::assertSame('https://ai.acme.example', Breadcrumb::originFrom($json));
    }

    /**
     * parse_url preserves the case it was given, but scheme and host are
     * case-insensitive per RFC 3986 — a mixed-case URL must still be
     * recognised rather than silently disabling the breadcrumb.
     */
    public function testOriginNormalisesSchemeAndHostCase(): void
    {
        $json = '{"metadata":{"relatedFiles":[{"url":"HTTPS://AI.Acme.EXAMPLE/llms.txt"}]}}';

        self::assertSame('https://ai.acme.example', Breadcrumb::originFrom($json));
    }

    public function testOriginKeepsANonDefaultPort(): void
    {
        $json = '{"metadata":{"relatedFiles":[{"url":"http://localhost:8080/llms.txt"}]}}';

        self::assertSame('http://localhost:8080', Breadcrumb::originFrom($json));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'not json' => ['not json at all'];
        yield 'no metadata' => ['{"name":"Acme"}'];
        yield 'relative links (dev disk backend)' => ['{"metadata":{"relatedFiles":[{"url":"llms.txt"}]}}'];
        yield 'unrelated basenames' => ['{"metadata":{"relatedFiles":[{"url":"https://ai.acme.example/content.md"}]}}'];
        yield 'non-http scheme' => ['{"metadata":{"relatedFiles":[{"url":"javascript:alert(1)/llms.txt"}]}}'];
        yield 'hostless' => ['{"metadata":{"relatedFiles":[{"url":"https:///llms.txt"}]}}'];
        yield 'relatedFiles not a list' => ['{"metadata":{"relatedFiles":"nope"}}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableProvider')]
    public function testOriginIsEmptyWhenUnusable(string $json): void
    {
        self::assertSame('', Breadcrumb::originFrom($json));
    }

    /**
     * Each relation points at the canonical copy of its own file: the apex for
     * everything this install serves, which since 0.7.0 is all four. Matches
     * Breadcrumbs::href() in gt-wordpress-plugin.
     */
    public function testHeadBlockPointsEachRelationAtItsCanonicalCopy(): void
    {
        self::assertSame(
            '<!-- Globetrotters — AI presence -->'."\n"
            .'<link rel="alternate" type="application/ld+json" href="/schema.json">'."\n"
            .'<link rel="ai-catalog" href="/.well-known/ai-catalog.json">'."\n"
            .'<link rel="mcp" href="/.well-known/mcp.json">'."\n"
            .'<link rel="agent-card" href="/.well-known/agent-card.json">'."\n"
            .'<link rel="related" href="https://ai.acme.example/">'."\n",
            Breadcrumb::headBlock('https://ai.acme.example', true),
        );
    }

    /**
     * Once every discovery file resolves to the apex, the relations above no
     * longer name the presence host at all, so the profile's pointer to it is a
     * relation of its own: on every page, whatever the cache holds.
     */
    public function testThePresenceHostIsAlwaysNamed(): void
    {
        foreach ([true, false] as $withAlternate) {
            self::assertStringContainsString(
                '<link rel="related" href="https://ai.acme.example/">',
                Breadcrumb::headBlock('https://ai.acme.example', $withAlternate),
            );
        }
        self::assertStringContainsString(
            'href="https://ai.acme.example/">',
            Breadcrumb::headBlock('https://ai.acme.example', true, static fn (string $path): bool => false),
        );
    }

    /**
     * The split is driven by ContentTypes, not a hardcoded list, so a file
     * added to the served set starts resolving locally on its own.
     */
    public function testLocallyServedRelationsAreRootRelative(): void
    {
        $block = Breadcrumb::headBlock('https://ai.acme.example', true);

        foreach (['schema.json', '.well-known/mcp.json', '.well-known/agent-card.json', '.well-known/ai-catalog.json'] as $path) {
            self::assertTrue(ContentTypes::has($path), $path.' is expected to be served locally');
            self::assertStringContainsString('href="/'.$path.'">', $block);
            self::assertStringNotContainsString('href="https://ai.acme.example/'.$path.'"', $block);
        }
    }

    /**
     * rel="alternate" points at a document describing the destination, so
     * claiming it as an interior page's alternate would be untrue. The
     * site-scoped relations are correct from any page and stay.
     */
    public function testAlternateIsOmittedOffTheHomepage(): void
    {
        $block = Breadcrumb::headBlock('https://ai.acme.example', false);

        self::assertStringNotContainsString('rel="alternate"', $block);
        self::assertStringContainsString('rel="ai-catalog"', $block);
        self::assertStringContainsString('rel="mcp"', $block);
        self::assertStringContainsString('rel="agent-card"', $block);
        self::assertStringStartsWith(Breadcrumb::MARKER, $block);
    }

    public function testHeadBlockIsEmptyWithoutAnOrigin(): void
    {
        self::assertSame('', Breadcrumb::headBlock('', true));
    }

    public function testAnchorRendersAVisibleLink(): void
    {
        self::assertSame(
            '<a href="https://ai.acme.example">AI presence for Acme</a>'."\n",
            Breadcrumb::anchor('https://ai.acme.example', 'AI presence for Acme'),
        );
    }

    public function testAnchorEscapesTheText(): void
    {
        $markup = Breadcrumb::anchor('https://ai.acme.example', 'Acme & <script>alert(1)</script>');

        self::assertStringContainsString('Acme &amp; &lt;script&gt;', $markup);
        self::assertStringNotContainsString('<script>', $markup);
    }

    public function testAnchorEscapesTheOrigin(): void
    {
        $markup = Breadcrumb::anchor('https://ai.acme.example/"><script>x</script>', 'Text');

        self::assertStringNotContainsString('<script>', $markup);
        self::assertStringContainsString('&quot;', $markup);
    }

    public function testAnchorIsEmptyWithoutTextOrOrigin(): void
    {
        self::assertSame('', Breadcrumb::anchor('', 'Text'));
        self::assertSame('', Breadcrumb::anchor('https://ai.acme.example', '   '));
    }

    public function testDefaultAnchorTextUsesTheDestinationName(): void
    {
        self::assertSame('AI presence for Acme', Breadcrumb::defaultAnchorText(self::AI_JSON));
    }

    public function testDefaultAnchorTextFallsBackWhenNameIsMissing(): void
    {
        self::assertSame('Our AI presence', Breadcrumb::defaultAnchorText('{"metadata":{}}'));
        self::assertSame('Our AI presence', Breadcrumb::defaultAnchorText('not json'));
    }
}
