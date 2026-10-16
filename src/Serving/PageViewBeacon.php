<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Analytics\PageViewOptions;

/**
 * The inline script that counts a human page view.
 *
 * It posts the page's path with ``navigator.sendBeacon`` to a root-relative
 * path on the page's **own origin**, answered by {@see PageViewEndpoint}. The
 * browser never talks to Globetrotters, no cookie or storage is touched, and a
 * browser without ``sendBeacon`` (or any error at all) simply does nothing.
 *
 * The body is ``{p, r, u}`` and nothing else: ``p`` the path (never the query
 * string), ``r`` the **host** of ``document.referrer`` — only when it differs
 * from the page's own, and never the referrer URL — and ``u`` the page's
 * ``utm_source`` value, capped at 100 characters. ``r`` and ``u`` are what the
 * endpoint matches against the AI referrer list; each is sent only when
 * present, and a referrer that does not parse is left out rather than fail
 * the count. Under the browsers' default ``strict-origin-when-cross-origin``
 * policy a cross-site referrer is already reduced to its origin, so the host
 * is all there is to send.
 * Crawlers do not run it, and a page cache cannot hide it: the script is in
 * the cached HTML, and the POST is never cached. A page the browser
 * *prerenders* (Speculation Rules) runs its scripts with nobody looking, so
 * the beacon waits for ``prerenderingchange`` — the moment it is shown — and
 * a prerender that is never shown counts nothing.
 *
 * Byte-identical to gt-wordpress-plugin's, so the same page counts the same
 * way on either producer; both repos pin the same literal in a test.
 */
final class PageViewBeacon
{
    public const PATH = '/.well-known/globetrotters/pv';

    /**
     * The script without its tag, so a nonce can be added to the tag and the
     * injector can recognise a copy placed with or without one.
     */
    public const SCRIPT_BODY = "(function(){var n=navigator,d=document,l=location;function s(){try{var b={p:l.pathname},r,u;try{r=new URL(d.referrer).host}catch(e){}if(r&&r!==l.host)b.r=r;try{u=new URLSearchParams(l.search).get('utm_source')}catch(e){}if(u)b.u=u.slice(0,100);n.sendBeacon('".self::PATH."',new Blob([JSON.stringify(b)],{type:'text/plain'}))}catch(e){}}try{if(!n.sendBeacon)return;if(d.prerendering){d.addEventListener('prerenderingchange',s,{once:true})}else{s()}}catch(e){}})();";

    public const SCRIPT = '<script>'.self::SCRIPT_BODY.'</script>';

    public function __construct(private readonly PageViewOptions $options)
    {
    }

    /**
     * The script, or '' unless the counter is on and reporting is configured.
     *
     * A site with a Content-Security-Policy that forbids inline scripts passes
     * its per-request nonce — ``gt_ai_presence_beacon(csp_nonce('script'))``
     * with NelmioSecurityBundle — and it is rendered, escaped, as the tag's
     * ``nonce`` attribute.
     */
    public function render(?string $nonce = null): string
    {
        if (!$this->options->isActive()) {
            return '';
        }
        if (null === $nonce || '' === $nonce) {
            return self::SCRIPT;
        }

        return '<script nonce="'.htmlspecialchars($nonce, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8').'">'.self::SCRIPT_BODY.'</script>';
    }
}
