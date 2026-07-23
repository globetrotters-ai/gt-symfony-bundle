<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

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
