<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Analytics\AiReferrers;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\PageViewCounter;
use Globetrotters\AiPresenceBundle\Analytics\PageViewRules;
use Globetrotters\AiPresenceBundle\Serving\PageViewBeacon;
use Globetrotters\AiPresenceBundle\Serving\Router;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\CookieStampingListener;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel;
use Symfony\Component\Clock\NativeClock;
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
        self::assertSame(['day' => $yesterday, 'path' => '/visiter/marche', 'bucket' => 'browser', 'platform' => 'none', 'count' => 2], \array_slice($envelope['pageViews'][0], 1));
        self::assertSame([], $this->counter()->pending(10));
        self::assertStringContainsString('1 page-view record', $tester->getDisplay());
    }

    /**
     * The acceptance run, end to end on the kernel: a referred visit, a
     * tagged visit and a plain one to the same page, matched against the list
     * the ranges sync stored, flushed the next day as 2 ``chatgpt`` views and
     * 1 ``none`` — and neither the referrer nor the parameter on disk.
     */
    public function testAViewFromAnAiAnswerIsCountedUnderItsPlatformAndFlushed(): void
    {
        $client = $this->bootClient();
        (new AiReferrers(new BufferDirectory($this->bufferDir()), new NativeClock()))
            ->store('3f2a9c1e0b7d', ['chatgpt.com' => 'chatgpt'], ['chatgpt.com' => 'chatgpt']);

        foreach (['{"p":"/x","r":"chatgpt.com"}', '{"p":"/x","u":"chatgpt.com"}', '{"p":"/x"}'] as $body) {
            $client->request('POST', PageViewBeacon::PATH, server: [
                'HTTP_HOST' => self::HOST,
                'HTTP_ORIGIN' => 'https://'.self::HOST,
                'HTTP_USER_AGENT' => self::CHROME,
                'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
            ], content: $body);
            self::assertSame(204, $client->getResponse()->getStatusCode());
        }

        $today = gmdate('Y-m-d');
        self::assertSame(["browser@chatgpt\t/x" => 2, "browser\t/x" => 1], $this->counter()->counts($today));
        foreach (glob($this->bufferDir().'/pageviews-*') ?: [] as $file) {
            self::assertStringNotContainsString('chatgpt.com', (string) file_get_contents($file));
            self::assertStringNotContainsString('utm_source', (string) file_get_contents($file));
        }

        // Next day: the flush seals and sends yesterday.
        rename($this->bufferDir().'/pageviews-'.$today.'.json', $this->bufferDir().'/pageviews-'.gmdate('Y-m-d', time() - 86400).'.json');
        $tester = $this->runCommand('gt:presence:flush', ['--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $records = $this->transport()->envelopes()[0]['pageViews'];
        self::assertSame(
            [['/x', 'browser', 'chatgpt', 2], ['/x', 'browser', 'none', 1]],
            array_map(static fn (array $record): array => [$record['path'], $record['bucket'], $record['platform'], $record['count']], $records),
        );
        self::assertStringNotContainsString('chatgpt.com', $this->transport()->sent[0]['json']);
    }

    public function testASignedBeaconCountsAsAnAiBrowserAndIsNeverAttributed(): void
    {
        $client = $this->bootClient();
        (new AiReferrers(new BufferDirectory($this->bufferDir()), new NativeClock()))
            ->store('3f2a9c1e0b7d', ['chatgpt.com' => 'chatgpt'], ['chatgpt.com' => 'chatgpt']);

        foreach (['"https://www.meta.com"', ''] as $agent) {
            $client->request('POST', PageViewBeacon::PATH, server: [
                'HTTP_HOST' => self::HOST,
                'HTTP_ORIGIN' => 'https://'.self::HOST,
                'HTTP_USER_AGENT' => self::CHROME,
                'HTTP_SIGNATURE_AGENT' => $agent,
                'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
            ], content: '{"p":"/x","r":"chatgpt.com"}');
            self::assertSame(204, $client->getResponse()->getStatusCode());
        }

        self::assertSame(
            ["ai_browser\t/x" => 1, "browser@chatgpt\t/x" => 1],
            $this->counter()->counts(gmdate('Y-m-d')),
            'signed: ai_browser, no platform; an empty Signature-Agent is not signed',
        );
    }

    public function testStatusShowsTheAiReferrerList(): void
    {
        $this->bootClient();

        self::assertMatchesRegularExpression('/AI referrer list\s+not fetched yet/', $this->runCommand('gt:status')->getDisplay());

        (new AiReferrers(new BufferDirectory($this->bufferDir()), new NativeClock()))
            ->store('3f2a9c1e0b7d', ['chatgpt.com' => 'chatgpt', 'claude.ai' => 'claude'], ['perplexity' => 'perplexity']);

        self::assertMatchesRegularExpression('/AI referrer list\s+fresh, version 3f2a9c1e0b7d, 2 hosts, 1 utm_source value/', $this->runCommand('gt:status')->getDisplay());
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
