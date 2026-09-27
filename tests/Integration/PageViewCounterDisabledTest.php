<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Serving\PageViewBeacon;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel;
use Twig\Environment;

/**
 * The default: reporting configured, page views not opted into. Nothing on the
 * page, nothing intercepted, nothing written.
 */
final class PageViewCounterDisabledTest extends IntegrationTestCase
{
    protected static bool $withReporting = true;

    public function testTheBeaconPathIsNotIntercepted(): void
    {
        $client = $this->bootClient();

        $client->request('POST', PageViewBeacon::PATH, server: [
            'HTTP_HOST' => 'www.example.com',
            'HTTP_ORIGIN' => 'https://www.example.com',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/140.0.0.0 Safari/537.36',
        ], content: '{"p":"/a"}');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('ANTAGONIST', $client->getResponse()->getContent(), 'the application answered it');

        $kernel = static::$kernel;
        \assert($kernel instanceof TestKernel);
        self::assertSame([], glob($kernel->bufferDir().'/pageviews-*') ?: []);
    }

    public function testNoScriptIsInjectedOrRendered(): void
    {
        $client = $this->bootClient();

        $client->request('GET', '/interior');
        self::assertStringNotContainsString('sendBeacon', (string) $client->getResponse()->getContent());

        $twig = static::getContainer()->get('twig');
        \assert($twig instanceof Environment);
        self::assertSame('', $twig->createTemplate('{{ gt_ai_presence_beacon() }}')->render());
    }

    public function testStatusSaysPageViewsAreOff(): void
    {
        $this->bootClient();

        self::assertMatchesRegularExpression('/Page views\s+off/', $this->runCommand('gt:status')->getDisplay());
    }
}
