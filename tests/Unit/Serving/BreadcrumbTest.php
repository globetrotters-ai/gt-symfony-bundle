<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Serving\Breadcrumb;
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
          "name": "Nantes",
          "organization": {"name": "Nantes", "url": "https://www.nantes-tourisme.com"},
          "url": "https://www.nantes-tourisme.com",
          "metadata": {
            "relatedFiles": [
              {"url": "https://ai.nantes.fr/llms.txt", "type": "text/plain"},
              {"url": "https://ai.nantes.fr/llms-full.txt", "type": "text/plain"},
              {"url": "https://ai.nantes.fr/schema.json", "type": "application/ld+json"},
              {"url": "https://ai.nantes.fr/content.md", "type": "text/markdown"}
            ]
          }
        }
        JSON;

    public function testOriginComesFromRelatedFiles(): void
    {
        self::assertSame('https://ai.nantes.fr', Breadcrumb::originFrom(self::AI_JSON));
    }

    /**
     * The regression this whole derivation exists to avoid: `url` is the
     * customer's own website, never the host we publish to.
     */
    public function testOriginIgnoresTheTopLevelUrlField(): void
    {
        $json = '{"url":"https://www.nantes-tourisme.com","metadata":{"relatedFiles":[{"url":"https://nantes.globetrotters.ai/llms.txt"}]}}';

        self::assertSame('https://nantes.globetrotters.ai', Breadcrumb::originFrom($json));
    }

    public function testOriginFollowsTheCustomHostAfterActivation(): void
    {
        $before = '{"metadata":{"relatedFiles":[{"url":"https://nantes.globetrotters.ai/llms.txt"}]}}';
        $after = '{"metadata":{"relatedFiles":[{"url":"https://ai.nantes.fr/llms.txt"}]}}';

        self::assertSame('https://nantes.globetrotters.ai', Breadcrumb::originFrom($before));
        self::assertSame('https://ai.nantes.fr', Breadcrumb::originFrom($after));
    }

    /**
     * llms-full.txt and content.md are the offloaded pair, so on an apex bundle
     * they can point somewhere else entirely. llms.txt / schema.json never are.
     */
    public function testOriginPrefersANeverOffloadedEntry(): void
    {
        $json = '{"metadata":{"relatedFiles":['
            .'{"url":"https://elsewhere.example/llms-full.txt"},'
            .'{"url":"https://ai.nantes.fr/llms.txt"}'
            .']}}';

        self::assertSame('https://ai.nantes.fr', Breadcrumb::originFrom($json));
    }

    public function testOriginFallsBackToSchemaJsonEntry(): void
    {
        $json = '{"metadata":{"relatedFiles":[{"url":"https://ai.nantes.fr/schema.json"}]}}';

        self::assertSame('https://ai.nantes.fr', Breadcrumb::originFrom($json));
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
        yield 'no metadata' => ['{"name":"Nantes"}'];
        yield 'relative links (dev disk backend)' => ['{"metadata":{"relatedFiles":[{"url":"llms.txt"}]}}'];
        yield 'unrelated basenames' => ['{"metadata":{"relatedFiles":[{"url":"https://ai.nantes.fr/content.md"}]}}'];
        yield 'non-http scheme' => ['{"metadata":{"relatedFiles":[{"url":"javascript:alert(1)/llms.txt"}]}}'];
        yield 'hostless' => ['{"metadata":{"relatedFiles":[{"url":"https:///llms.txt"}]}}'];
        yield 'relatedFiles not a list' => ['{"metadata":{"relatedFiles":"nope"}}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableProvider')]
    public function testOriginIsEmptyWhenUnusable(string $json): void
    {
        self::assertSame('', Breadcrumb::originFrom($json));
    }

    public function testHeadBlockMirrorsTheStudioSnippet(): void
    {
        self::assertSame(
            '<!-- Globetrotters — AI presence -->'."\n"
            .'<link rel="alternate" type="application/ld+json" href="https://ai.nantes.fr/schema.json">'."\n"
            .'<link rel="ai-catalog" href="https://ai.nantes.fr/.well-known/ai-catalog.json">'."\n"
            .'<link rel="mcp" href="https://ai.nantes.fr/.well-known/mcp.json">'."\n"
            .'<link rel="agent-card" href="https://ai.nantes.fr/.well-known/agent-card.json">'."\n",
            Breadcrumb::headBlock('https://ai.nantes.fr'),
        );
    }

    public function testHeadBlockIsEmptyWithoutAnOrigin(): void
    {
        self::assertSame('', Breadcrumb::headBlock(''));
    }

    public function testAnchorRendersAVisibleLink(): void
    {
        self::assertSame(
            '<a href="https://ai.nantes.fr">AI presence for Nantes</a>'."\n",
            Breadcrumb::anchor('https://ai.nantes.fr', 'AI presence for Nantes'),
        );
    }

    public function testAnchorEscapesTheText(): void
    {
        $markup = Breadcrumb::anchor('https://ai.nantes.fr', 'Nantes & <script>alert(1)</script>');

        self::assertStringContainsString('Nantes &amp; &lt;script&gt;', $markup);
        self::assertStringNotContainsString('<script>', $markup);
    }

    public function testAnchorEscapesTheOrigin(): void
    {
        $markup = Breadcrumb::anchor('https://ai.nantes.fr/"><script>x</script>', 'Text');

        self::assertStringNotContainsString('<script>', $markup);
        self::assertStringContainsString('&quot;', $markup);
    }

    public function testAnchorIsEmptyWithoutTextOrOrigin(): void
    {
        self::assertSame('', Breadcrumb::anchor('', 'Text'));
        self::assertSame('', Breadcrumb::anchor('https://ai.nantes.fr', '   '));
    }

    public function testAnchorGuardsMatchAnyAnchorToTheOrigin(): void
    {
        $guards = Breadcrumb::anchorGuards('https://ai.nantes.fr');
        // Not array_any(): that is PHP 8.4+, and this project supports 8.2.
        $matches = static function (string $markup) use ($guards): bool {
            foreach ($guards as $guard) {
                if (str_contains($markup, $guard)) {
                    return true;
                }
            }

            return false;
        };

        self::assertTrue($matches(Breadcrumb::anchor('https://ai.nantes.fr', 'One text')));
        self::assertTrue($matches(Breadcrumb::anchor('https://ai.nantes.fr', 'Another text')));
        self::assertFalse($matches(Breadcrumb::anchor('https://other.example', 'One text')));
    }

    /** A hand-written link to a host root very often carries the slash. */
    public function testAnchorGuardsCoverTheTrailingSlashForm(): void
    {
        $guards = Breadcrumb::anchorGuards('https://ai.nantes.fr');

        self::assertContains('<a href="https://ai.nantes.fr"', $guards);
        self::assertContains('<a href="https://ai.nantes.fr/"', $guards);
    }

    /**
     * Without the closing quote each guard would also match a lookalike host
     * and silently suppress a legitimate anchor.
     */
    public function testAnchorGuardsDoNotMatchALookalikeHost(): void
    {
        foreach (Breadcrumb::anchorGuards('https://ai.nantes.fr') as $guard) {
            self::assertStringNotContainsString($guard, '<a href="https://ai.nantes.fr.example.test">x</a>');
        }
    }

    public function testAnchorGuardsAreEmptyWithoutAnOrigin(): void
    {
        self::assertSame([], Breadcrumb::anchorGuards(''));
    }

    public function testDefaultAnchorTextUsesTheDestinationName(): void
    {
        self::assertSame('AI presence for Nantes', Breadcrumb::defaultAnchorText(self::AI_JSON));
    }

    public function testDefaultAnchorTextFallsBackWhenNameIsMissing(): void
    {
        self::assertSame('Our AI presence', Breadcrumb::defaultAnchorText('{"metadata":{}}'));
        self::assertSame('Our AI presence', Breadcrumb::defaultAnchorText('not json'));
    }
}
