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
 * Crawlers do not run it, and a page cache cannot hide it: the script is in
 * the cached HTML, and the POST is never cached.
 *
 * Byte-identical to gt-wordpress-plugin's, so the same page counts the same
 * way on either producer.
 */
final class PageViewBeacon
{
    public const PATH = '/.well-known/globetrotters/pv';

    public const SCRIPT = "<script>(function(){try{var n=navigator;if(!n.sendBeacon)return;n.sendBeacon('".self::PATH."',new Blob([JSON.stringify({p:location.pathname})],{type:'text/plain'}))}catch(e){}})();</script>";

    public function __construct(private readonly PageViewOptions $options)
    {
    }

    /**
     * The script, or '' unless the counter is on and reporting is configured.
     */
    public function render(): string
    {
        return $this->options->isActive() ? self::SCRIPT : '';
    }
}
