<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\PageViewCounter;
use Globetrotters\AiPresenceBundle\Analytics\PageViewRules;
use Globetrotters\AiPresenceBundle\Serving\PageViewBeacon;
use Globetrotters\AiPresenceBundle\Serving\Router;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\CookieStampingListener;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel;
use Symfony\Component\Console\Command\Command;
use Twig\Environment;

/**
 * The first-party page-view counter, end to end: the script on the page, the
 * same-origin beacon, the counter file, and the flush that reports closed days.
 */
final class PageViewCounterTest extends IntegrationTestCase
{
    protected static bool $withReporting = true;
    protected static bool $withPageViews = true;

    private const HOST = 'www.example.com';
    private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    public function testTheBeaconIsCountedAndAnswered204WithNoCookie(): void
    {
        $client = $this->bootClient();

        $client->request('POST', PageViewBeacon::PATH, server: [
            'HTTP_HOST' => self::HOST,
            'HTTP_ORIGIN' => 'https://'.self::HOST,
            'HTTP_USER_AGENT' => self::CHROME,
            'REMOTE_ADDR' => '203.0.113.77',
            'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
        ], content: '{"p":"/visiter/marche/"}');

        $response = $client->getResponse();
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        foreach (Router::NO_STORE_HEADERS as $name => $value) {
            self::assertSame($value, $response->headers->get($name), $name);
        }
        self::assertSame([], $response->headers->getCookies(), 'not even the host application\'s own cookie');
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertFalse($response->headers->has('Access-Control-Allow-Origin'), 'same-origin only: no CORS grant');

        self::assertSame(["browser\t/visiter/marche" => 1], $this->counter()->counts(gmdate('Y-m-d')));

        // Inspect what was written: counts only, no IP, no User-Agent.
        $written = '';
        foreach (glob($this->bufferDir().'/*') ?: [] as $file) {
            $written .= file_get_contents($file);
        }
        self::assertStringNotContainsString('203.0.113.77', $written);
        self::assertStringNotContainsString('Mozilla', $written);
        self::assertFileDoesNotExist($this->bufferDir().'/events.ndjson', 'a page view is not an agent event');
    }

    /**
     * The control for the cookie assertion above: the fixture listener does
     * stamp its cookie on the application's own responses.
     */
    public function testTheApplicationsOwnPagesKeepTheirCookieAndGetTheScript(): void
    {
        $client = $this->bootClient();

        $client->request('GET', '/interior');

        $response = $client->getResponse();
        self::assertSame(CookieStampingListener::COOKIE, $response->headers->getCookies()[0]->getName());
        self::assertStringContainsString(PageViewBeacon::SCRIPT.'</body>', (string) $response->getContent());
    }

    public function testACrossOriginBeaconIsDiscarded(): void
    {
        $client = $this->bootClient();

        $client->request('POST', PageViewBeacon::PATH, server: [
            'HTTP_HOST' => self::HOST,
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_USER_AGENT' => self::CHROME,
        ], content: '{"p":"/a"}');

        self::assertSame(204, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->counter()->counts(gmdate('Y-m-d')));
    }

    public function testTheTwigFunctionRendersTheScript(): void
    {
        $this->bootClient();
        $twig = static::getContainer()->get('twig');
        \assert($twig instanceof Environment);

        self::assertSame(PageViewBeacon::SCRIPT, $twig->createTemplate('{{ gt_ai_presence_beacon() }}')->render());
        self::assertSame(
            '<script nonce="n0nce&quot;">'.PageViewBeacon::SCRIPT_BODY.'</script>',
            $twig->createTemplate('{{ gt_ai_presence_beacon(nonce) }}')->render(['nonce' => 'n0nce"']),
        );
    }

    /**
     * The cron lane flushes page views with no agent event buffered at all —
     * nothing about it depends on an artefact request having happened.
     */
    public function testTheConsoleLaneFlushesAPageViewOnlyEnvelope(): void
    {
        $this->bootClient();
        $yesterday = gmdate('Y-m-d', time() - 86400);
        $this->counter()->increment($yesterday, '/visiter/marche', PageViewRules::BUCKET_BROWSER);
        $this->counter()->increment($yesterday, '/visiter/marche', PageViewRules::BUCKET_BROWSER);
        $this->counter()->increment(gmdate('Y-m-d'), '/today', PageViewRules::BUCKET_BROWSER);

        $tester = $this->runCommand('gt:presence:flush', ['--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $envelope = $this->transport()->envelopes()[0];
        self::assertSame([], $envelope['events']);
        self::assertCount(1, $envelope['pageViews']);
        self::assertSame(['day' => $yesterday, 'path' => '/visiter/marche', 'bucket' => 'browser', 'count' => 2], \array_slice($envelope['pageViews'][0], 1));
        self::assertSame([], $this->counter()->pending(10));
        self::assertStringContainsString('1 page-view record', $tester->getDisplay());
    }

    public function testStatusShowsPendingPageViews(): void
    {
        $this->bootClient();
        $this->counter()->increment(gmdate('Y-m-d'), '/a', PageViewRules::BUCKET_BROWSER);

        $display = $this->runCommand('gt:status')->getDisplay();

        self::assertMatchesRegularExpression('/Page views\s+on, auto-injected/', $display);
        self::assertMatchesRegularExpression('/Page views pending\s+0 record\(s\) sealed; 1 view\(s\) counted on open days/', $display);
    }

    private function counter(): PageViewCounter
    {
        return new PageViewCounter(new BufferDirectory($this->bufferDir()));
    }

    private function bufferDir(): string
    {
        $kernel = static::$kernel;
        \assert($kernel instanceof TestKernel);

        return $kernel->bufferDir();
    }
}
