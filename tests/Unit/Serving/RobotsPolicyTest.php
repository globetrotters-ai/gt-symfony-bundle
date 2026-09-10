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

    public function testConsecutiveUserAgentsShareAGroupAndAMemberLineClosesIt(): void
    {
        $policy = RobotsPolicy::parse("User-agent: *\nUser-agent: CCBot\nDisallow: /a\nUser-agent: GPTBot\nDisallow: /b\n");

        self::assertSame(['Disallow: /a'], $policy->wildcardLines());
        self::assertTrue($policy->names('CCBot'));
        self::assertTrue($policy->names('GPTBot'));
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
