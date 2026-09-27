<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\PageViewRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageViewRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validPaths(): iterable
    {
        yield 'root' => ['/', '/'];
        yield 'plain' => ['/visiter/marche', '/visiter/marche'];
        yield 'trailing slash folded' => ['/visiter/marche/', '/visiter/marche'];
        yield 'several trailing slashes folded' => ['/visiter//', '/visiter'];
        yield 'percent-encoded kept verbatim' => ['/caf%C3%A9', '/caf%C3%A9'];
        yield 'exactly 512 chars' => ['/'.str_repeat('a', 511), '/'.str_repeat('a', 511)];
    }

    #[DataProvider('validPaths')]
    public function testNormalisesAValidPath(string $raw, string $expected): void
    {
        self::assertSame($expected, PageViewRules::normalizePath($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'relative' => ['visiter'];
        yield 'protocol-relative' => ['//evil.example/x'];
        yield 'absolute url' => ['https://evil.example/x'];
        yield 'query string' => ['/search?q=a'];
        yield 'fragment' => ['/page#top'];
        yield 'too long' => ['/'.str_repeat('a', 512)];
        yield 'control character' => ["/a\nb"];
        yield 'literal other is not a client path' => ['(other)'];
        yield 'invalid utf-8' => ["/\xC3\x28"];
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsAnInvalidPath(string $raw): void
    {
        self::assertNull(PageViewRules::normalizePath($raw));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function userAgents(): iterable
    {
        $chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

        yield 'empty is discarded' => ['', null];
        yield 'blank is discarded' => ['   ', null];
        yield 'desktop browser' => [$chrome, PageViewRules::BUCKET_BROWSER];
        yield 'mobile safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', PageViewRules::BUCKET_BROWSER];
        yield 'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', null];
        yield 'crawler' => ['SomeCrawler/1.0', null];
        yield 'spider' => ['Baiduspider', null];
        yield 'slurp' => ['Mozilla/5.0 (compatible; Yahoo! Slurp)', null];
        yield 'python' => ['python-requests/2.32', null];
        yield 'curl' => ['curl/8.7.1', null];
        yield 'wget' => ['Wget/1.21', null];
        yield 'go' => ['Go-http-client/2.0', null];
        yield 'headless chrome' => ['Mozilla/5.0 HeadlessChrome/140.0.0.0', null];
        yield 'phantomjs' => ['Mozilla/5.0 PhantomJS/2.1.1', null];
        yield 'lighthouse' => ['Mozilla/5.0 Chrome-Lighthouse', null];
        yield 'link preview' => ['Mozilla/5.0 (compatible; LinkPreview/1.0)', null];
        yield 'fetcher' => ['node-fetch/1.0', null];
        yield 'chatgpt agent' => [$chrome.' ChatGPT-Agent/1.0', PageViewRules::BUCKET_AI_BROWSER];
        yield 'oai marker' => [$chrome.' OAI-Operator', PageViewRules::BUCKET_AI_BROWSER];
        yield 'comet' => [$chrome.' Comet/1.0', PageViewRules::BUCKET_AI_BROWSER];
        yield 'perplexity' => [$chrome.' Perplexity-Comet', PageViewRules::BUCKET_AI_BROWSER];
        yield 'claude' => [$chrome.' Claude-Browser', PageViewRules::BUCKET_AI_BROWSER];
        yield 'bot marker wins over ai marker' => ['ClaudeBot/1.0', null];
        yield 'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', null];
        yield 'a CUBOT phone is a person' => ['Mozilla/5.0 (Linux; Android 13; CUBOT X30) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', PageViewRules::BUCKET_BROWSER];
    }

    #[DataProvider('userAgents')]
    public function testBucketsTheUserAgentCoarsely(string $userAgent, ?string $expected): void
    {
        self::assertSame($expected, PageViewRules::bucket($userAgent));
    }
}
