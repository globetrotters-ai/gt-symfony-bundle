<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\PageViewOptions;
use Globetrotters\AiPresenceBundle\Serving\PageViewBeacon;
use PHPUnit\Framework\TestCase;

final class PageViewBeaconTest extends TestCase
{
    /**
     * The literal shared with gt-wordpress-plugin, which pins the same string:
     * the two producers must count the same page the same way. Change both
     * together, and the README's copy with them.
     */
    public const SCRIPT_BODY = "(function(){var n=navigator,d=document,l=location;function s(){try{var b={p:l.pathname},r,u;try{r=new URL(d.referrer).host}catch(e){}if(r&&r!==l.host)b.r=r;try{u=new URLSearchParams(l.search).get('utm_source')}catch(e){}if(u&&u.length<=100)b.u=u;n.sendBeacon('/.well-known/globetrotters/pv',new Blob([JSON.stringify(b)],{type:'text/plain'}))}catch(e){}}try{if(!n.sendBeacon)return;if(d.prerendering){d.addEventListener('prerenderingchange',s,{once:true})}else{s()}}catch(e){}})();";

    public function testIsTheDocumentedScript(): void
    {
        self::assertSame(self::SCRIPT_BODY, PageViewBeacon::SCRIPT_BODY);
        self::assertSame('<script>'.self::SCRIPT_BODY.'</script>', PageViewBeacon::SCRIPT);
        self::assertSame('<script>'.PageViewBeacon::SCRIPT_BODY.'</script>', PageViewBeacon::SCRIPT);
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

    /**
     * AC2: the body carries ``p``, ``r`` and ``u`` and nothing else — no
     * full referrer URL, no query string beyond the one parameter, no title.
     */
    public function testPostsThePathTheReferrerHostAndTheUtmSourceOnly(): void
    {
        $script = PageViewBeacon::SCRIPT_BODY;

        self::assertSame(2, preg_match_all('/\bb\.([a-z]+)=/', $script, $keys));
        self::assertSame(['r', 'u'], array_values(array_unique($keys[1])));
        self::assertStringContainsString('var b={p:l.pathname}', $script, 'p always, and only the pathname');
        self::assertStringContainsString('JSON.stringify(b)', $script);
        self::assertStringContainsString('new URL(d.referrer).host', $script, 'the host only, never the referrer URL');
        self::assertStringContainsString('if(r&&r!==l.host)b.r=r', $script, 'never the page\'s own host');
        self::assertStringContainsString(".get('utm_source')", $script, 'one parameter, not the query string');
        self::assertStringContainsString('if(u&&u.length<=100)b.u=u;', $script, 'an over-long value is dropped, never cut mid-character');
        self::assertStringNotContainsString('slice(', $script);
        self::assertStringNotContainsString('location.search', $script);
        self::assertStringNotContainsString('d.referrer)', str_replace('new URL(d.referrer)', '', $script), 'the referrer is only ever parsed');
    }

    /**
     * Speculation Rules prerendering runs the script with nobody looking at
     * the page; the view is sent only once the page is actually shown.
     */
    public function testWaitsForAPrerenderedPageToBeShown(): void
    {
        self::assertStringContainsString("if(d.prerendering){d.addEventListener('prerenderingchange',s,{once:true})}else{s()}", PageViewBeacon::SCRIPT);
        self::assertSame(1, substr_count(PageViewBeacon::SCRIPT, 'sendBeacon('), 'one call site, reached either way');
    }

    public function testRendersACspNonceEscaped(): void
    {
        $beacon = new PageViewBeacon($this->options(true, true));

        self::assertSame('<script nonce="r4nd0m">'.PageViewBeacon::SCRIPT_BODY.'</script>', $beacon->render('r4nd0m'));
        self::assertSame('<script nonce="a&quot;&gt;&lt;x">'.PageViewBeacon::SCRIPT_BODY.'</script>', $beacon->render('a"><x'));
        self::assertSame(PageViewBeacon::SCRIPT, $beacon->render(''));
        self::assertSame(PageViewBeacon::SCRIPT, $beacon->render(null));
        self::assertSame('', (new PageViewBeacon($this->options(false, true)))->render('r4nd0m'));
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
