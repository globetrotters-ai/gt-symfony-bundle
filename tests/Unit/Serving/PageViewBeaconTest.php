<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\PageViewOptions;
use Globetrotters\AiPresenceBundle\Serving\PageViewBeacon;
use PHPUnit\Framework\TestCase;

final class PageViewBeaconTest extends TestCase
{
    public function testIsTheDocumentedScript(): void
    {
        self::assertSame(
            "<script>(function(){try{var n=navigator;if(!n.sendBeacon)return;n.sendBeacon('/.well-known/globetrotters/pv',new Blob([JSON.stringify({p:location.pathname})],{type:'text/plain'}))}catch(e){}})();</script>",
            PageViewBeacon::SCRIPT,
        );
        self::assertSame('/.well-known/globetrotters/pv', PageViewBeacon::PATH);
    }

    /**
     * The browser never talks to Globetrotters: the script posts to a
     * root-relative path on the page's own origin, and names no other host.
     */
    public function testOnlyEverTargetsTheSameOrigin(): void
    {
        $script = PageViewBeacon::SCRIPT;

        self::assertLessThan(1024, \strlen($script));
        self::assertDoesNotMatchRegularExpression('#[a-z][a-z0-9+.-]*://#i', $script, 'no absolute URL');
        self::assertStringNotContainsString("'//", $script, 'no protocol-relative URL');
        self::assertStringNotContainsStringIgnoringCase('globetrotters.ai', $script);
        self::assertStringNotContainsString('document.cookie', $script);
        self::assertStringNotContainsString('localStorage', $script);
        self::assertSame(1, preg_match_all("#sendBeacon\\('([^']*)'#", $script, $targets));
        self::assertSame([PageViewBeacon::PATH], $targets[1]);
        self::assertStringStartsWith('/', $targets[1][0]);
        self::assertStringStartsNotWith('//', $targets[1][0]);
    }

    public function testRendersOnlyWhenEnabledAndReportingIsConfigured(): void
    {
        self::assertSame(PageViewBeacon::SCRIPT, (new PageViewBeacon($this->options(true, true)))->render());
        self::assertSame('', (new PageViewBeacon($this->options(false, true)))->render());
        self::assertSame('', (new PageViewBeacon($this->options(true, false)))->render());
    }

    private function options(bool $enabled, bool $configured): PageViewOptions
    {
        return new PageViewOptions(
            new AnalyticsOptions(true, $configured ? 'https://api.test/ingest' : '', 'token', true, false),
            $enabled,
            false,
        );
    }
}
