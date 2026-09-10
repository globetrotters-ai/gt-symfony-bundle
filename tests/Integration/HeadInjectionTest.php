<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Client\FetchResult;
use Twig\Environment;

final class HeadInjectionTest extends IntegrationTestCase
{
    public function testJsonLdIsInTheRawHomepageHtml(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', '/');
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('<script type="application/ld+json">', $content);
        self::assertStringContainsString('TouristDestination', $content);
        // Injected inside the head, before the closing tag.
        self::assertMatchesRegularExpression('~</script>\n</head>~', $content);
    }

    /**
     * A schema.json value that parks the HTML tokenizer in the double-escaped
     * script state must not swallow the page behind the injected tag.
     *
     * Parsed with PHP 8.4's HTML5-conformant \Dom\HTMLDocument, the only
     * parser here that implements the script-data state machine; the
     * structural guarantee it relies on (no "<" in the payload) is asserted on
     * every PHP version by HeadInjectorTest.
     */
    #[\PHPUnit\Framework\Attributes\RequiresPhp('>= 8.4')]
    public function testAHostileSchemaValueLeavesTheHomepageBodyIntact(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->fetcher()->on('/schema.json', FetchResult::http(200, '{"@context":"https://schema.org","name":"<!--<script>"}'));
        $this->refresh();

        $client->request('GET', '/');
        $html = (string) $client->getResponse()->getContent();

        $document = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR);
        self::assertSame('Homepage', $document->body?->textContent);

        $script = $document->querySelector('script[type="application/ld+json"]');
        self::assertNotNull($script);
        $decoded = json_decode($script->textContent, true);
        self::assertIsArray($decoded);
        self::assertSame('<!--<script>', $decoded['name']);
    }

    public function testNoInjectionOffHomepage(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', '/some-page');

        self::assertStringNotContainsString('ld+json', (string) $client->getResponse()->getContent());
    }

    public function testNoInjectionWhenCacheCold(): void
    {
        $client = $this->bootClient();

        $client->request('GET', '/');

        self::assertStringNotContainsString('ld+json', (string) $client->getResponse()->getContent());
    }

    public function testTwigFunctionRendersTheTag(): void
    {
        $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $twig = static::getContainer()->get('twig');
        \assert($twig instanceof Environment);
        $rendered = $twig->createTemplate('{{ gt_ai_presence_head() }}')->render();

        self::assertStringContainsString('<script type="application/ld+json">', $rendered);
        self::assertStringContainsString('TouristDestination', $rendered);
    }
}
