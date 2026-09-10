<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Serving\RobotsPolicy;
use PHPUnit\Framework\TestCase;

final class RobotsPolicyTest extends TestCase
{
    public function testAgentsAreMatchedTheWayCrawlersMatchThem(): void
    {
        $policy = RobotsPolicy::parse("User-agent: GPTBot/1.1\nUser-agent: claudebot\nDisallow: /\n");

        self::assertTrue($policy->names('GPTBot'));
        self::assertTrue($policy->names('ClaudeBot'));
        self::assertFalse($policy->names('CCBot'));
        self::assertFalse($policy->names('*'));
    }

    public function testConsecutiveUserAgentsShareAGroupAndARuleClosesIt(): void
    {
        $policy = RobotsPolicy::parse("User-agent: *\nUser-agent: CCBot\nDisallow: /a\nUser-agent: GPTBot\nDisallow: /b\n");

        self::assertSame(['Disallow: /a'], $policy->wildcardLines());
        self::assertTrue($policy->names('CCBot'));
        self::assertTrue($policy->names('GPTBot'));
    }

    /**
     * The reported file. Google groups on Allow/Disallow only ("Rules other
     * than allow, disallow, and user-agent are ignored"), so the Content-Signal
     * does not end the section and ``*`` shares GPTBot's rule.
     */
    public function testAContentSignalBetweenUserAgentsDoesNotEndTheSection(): void
    {
        $policy = RobotsPolicy::parse("User-agent: *\nContent-Signal: ai-train=no\nUser-agent: GPTBot\nDisallow: /private\n");

        self::assertTrue($policy->names('GPTBot'));
        self::assertSame(['Content-Signal: ai-train=no', 'Disallow: /private'], $policy->wildcardLines());
        self::assertFalse($policy->endsInsideAGroup());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonRuleLines')]
    public function testOnlyARuleEndsAUserAgentSection(string $line, array $expected): void
    {
        $policy = RobotsPolicy::parse("User-agent: GPTBot\n".$line."\nUser-agent: *\nDisallow: /private\n");

        self::assertTrue($policy->names('GPTBot'));
        // A line written before ``*`` joined the section still belongs to it.
        self::assertSame($expected, $policy->wildcardLines());
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function nonRuleLines(): iterable
    {
        yield 'content-signal' => ['Content-Signal: search=yes', ['Content-Signal: search=yes', 'Disallow: /private']];
        yield 'crawl-delay' => ['Crawl-delay: 10', ['Crawl-delay: 10', 'Disallow: /private']];
        yield 'unknown directive' => ['Noindex: /tmp', ['Noindex: /tmp', 'Disallow: /private']];
        yield 'sitemap' => ['Sitemap: https://x.test/sitemap.xml', ['Disallow: /private']];
        yield 'comment' => ['# a note', ['Disallow: /private']];
        yield 'blank line' => ['', ['Disallow: /private']];
    }

    public function testAllowAndDisallowDoEndASection(): void
    {
        $policy = RobotsPolicy::parse(
            "User-agent: *\nAllow: /public\nUser-agent: GPTBot\nDisallow: /\n"
            ."User-agent: *\nDisallow: /tmp\nUser-agent: CCBot\nDisallow: /\n",
        );

        self::assertSame(['Allow: /public', 'Disallow: /tmp'], $policy->wildcardLines());
        self::assertTrue($policy->names('GPTBot'));
        self::assertTrue($policy->names('CCBot'));
    }

    public function testMetadataAfterARuleStaysWithThatGroup(): void
    {
        $policy = RobotsPolicy::parse("User-agent: *\nDisallow: /a\nCrawl-delay: 5\nUser-agent: GPTBot\nDisallow: /b\n");

        self::assertSame(['Disallow: /a', 'Crawl-delay: 5'], $policy->wildcardLines());
    }

    /**
     * RFC 9309 product tokens are letters, "-" and "_", and Google stops at the
     * first other character: ``GPTBot2`` names GPTBot, ``*bot`` names nothing,
     * and ``* everyone`` is still the wildcard.
     */
    public function testProductTokensAreReadTheWayCrawlersReadThem(): void
    {
        $policy = RobotsPolicy::parse("User-agent: GPTBot2\nUser-agent: Googlebot-Image/1.0\nDisallow: /\n\nUser-agent: *bot\nDisallow: /b\n\nUser-agent: * everyone\nDisallow: /all\n");

        self::assertTrue($policy->names('GPTBot'));
        self::assertTrue($policy->names('Googlebot-Image'));
        self::assertFalse($policy->names('Googlebot'));
        self::assertSame(['Disallow: /all'], $policy->wildcardLines());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fileEndings')]
    public function testReportsWhetherTheFileEndsInsideAnOpenSection(string $robots, bool $open): void
    {
        self::assertSame($open, RobotsPolicy::parse($robots)->endsInsideAGroup());
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function fileEndings(): iterable
    {
        yield 'closed by a rule' => ["User-agent: *\nDisallow: /a\n", false];
        yield 'closed by an empty rule' => ["User-agent: *\nDisallow:\n", false];
        yield 'open after a signal' => ["User-agent: *\nContent-Signal: ai-train=no\n", true];
        yield 'open after a sitemap' => ["User-agent: *\nSitemap: https://x.test/s.xml\n", true];
        yield 'open after a bare user-agent' => ["User-agent: GPTBot\nDisallow: /\nUser-agent: CCBot\n", true];
        yield 'no groups at all' => ["Sitemap: https://x.test/s.xml\n", false];
        yield 'empty' => ['', false];
    }

    public function testEveryWildcardGroupIsCombinedInFileOrder(): void
    {
        $policy = RobotsPolicy::parse("User-agent: *\nDisallow: /a\n\nUser-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nCrawl-delay: 5\n");

        self::assertSame(['Disallow: /a', 'Crawl-delay: 5'], $policy->wildcardLines());
    }

    public function testCommentsSitemapsAndLinesOutsideAGroupAreNotMembers(): void
    {
        $policy = RobotsPolicy::parse(
            "# house rules\nDisallow: /before-any-group\nSitemap: https://x.test/a.xml\n"
            ."User-agent: * # everyone\nDisallow: /admin # back office\nSitemap: https://x.test/b.xml\n",
        );

        self::assertSame(['Disallow: /admin'], $policy->wildcardLines());
    }

    /**
     * RFC 9309 permits empty lines inside a group; it is the next User-agent
     * after a member line that starts a new one.
     */
    public function testABlankLineDoesNotEndAGroup(): void
    {
        self::assertSame(['Disallow: /admin'], RobotsPolicy::parse("User-agent: *\n\nDisallow: /admin\n")->wildcardLines());
    }

    public function testCrlfLineEndingsAndAByteOrderMark(): void
    {
        $policy = RobotsPolicy::parse("\xEF\xBB\xBFUser-agent: GPTBot\r\nDisallow: /\r\n\r\nUser-agent: *\r\nDisallow: /admin\r\n");

        self::assertTrue($policy->names('GPTBot'));
        self::assertSame(['Disallow: /admin'], $policy->wildcardLines());
    }

    public function testAFileWithoutAWildcardGroupLeavesNothingToInherit(): void
    {
        self::assertSame([], RobotsPolicy::parse("User-agent: GPTBot\nDisallow: /\n")->wildcardLines());
        self::assertSame([], RobotsPolicy::parse('')->wildcardLines());
    }
}
