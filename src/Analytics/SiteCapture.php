<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Site bot capture (design B): one site event per HTML page of the
 * application fetched by a probable bot.
 *
 * The gate is coarse on purpose and evaluated before anything is written:
 * fine classification happens on the backend, so a new crawler needs no
 * release here. Kept in step with ``gt-wordpress-plugin``'s ``SiteCapture``.
 *
 * What a site event carries: the User-Agent, the page path (no query string),
 * the final status, the byte count when known, and whether the client IP fell
 * in a synced AI vendor range ({@see VendorRanges}). The IP is resolved with
 * the same trusted-proxy rules as artefact events, matched in memory, and
 * dropped: it is never written to the buffer nor sent.
 */
final class SiteCapture
{
    /**
     * A broad bot marker. Matching it, or lacking ``Mozilla/`` altogether,
     * passes the gate.
     */
    public const UA_PATTERN = '/bot|crawl|spider|slurp|fetch|compatible;|gpt|claude|perplexity|python|curl|wget|go-http|headlesschrome|phantomjs/i';

    /** Path prefixes that are never site pages: the Symfony profiler and toolbar. */
    public const SKIPPED_PREFIXES = ['/_profiler', '/_wdt'];

    /** Static asset extensions, never site pages whatever they are served as. */
    public const ASSET_EXTENSIONS = [
        'css', 'js', 'mjs', 'map', 'json', 'xml', 'txt', 'csv',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp3', 'mp4', 'webm', 'ogg', 'wav', 'pdf', 'zip', 'gz', 'wasm',
    ];

    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly AnalyticsOptions $options,
        private readonly ClientIpResolver $ipResolver,
        private readonly VendorRanges $ranges,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Whether a User-Agent passes the gate. An empty one lacks a browser
     * token, so it does.
     */
    public static function isProbableBot(string $userAgent): bool
    {
        return 1 === preg_match(self::UA_PATTERN, $userAgent) || !str_contains($userAgent, 'Mozilla/');
    }

    /**
     * Whether a request path can be a site page at all.
     */
    public static function isPagePath(string $path): bool
    {
        foreach (self::SKIPPED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return false;
            }
        }

        $last = (string) strrchr($path, '/');
        $dot = strrpos($last, '.');
        if (false !== $dot) {
            $extension = strtolower(substr($last, $dot + 1));
            if (\in_array($extension, self::ASSET_EXTENSIONS, true)) {
                return false;
            }
        }

        return true;
    }

    public static function isHtml(Response $response): bool
    {
        $type = strtolower(trim((string) $response->headers->get('Content-Type', '')));

        return str_starts_with($type, 'text/html');
    }

    /**
     * Body size when it can be known without buffering a stream: the
     * Content-Length header, else the length of a plain response's content.
     * Null for a streamed or file response, and for a HEAD without a length
     * (its content was emptied before it was sent).
     */
    public static function bytes(Request $request, Response $response): ?int
    {
        $length = $response->headers->get('Content-Length');
        if (null !== $length && ctype_digit(trim($length))) {
            return (int) trim($length);
        }
        if ($request->isMethod('HEAD') || $response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return null;
        }
        $content = $response->getContent();

        return \is_string($content) ? \strlen($content) : null;
    }

    /**
     * Capture one request that has already passed the routing-level checks
     * (main request, not one of ours). Applies the rest of the gate. Never
     * throws.
     */
    public function capture(Request $request, Response $response): bool
    {
        try {
            if (!$this->options->isSiteCaptureEnabled()) {
                return false;
            }
            if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
                return false;
            }
            if (!self::isHtml($response)) {
                return false;
            }
            $path = PageViewRules::normalizePath($request->getPathInfo());
            if (null === $path || !self::isPagePath($path)) {
                return false;
            }
            $userAgent = (string) $request->headers->get('User-Agent', '');
            if (!self::isProbableBot($userAgent)) {
                return false;
            }
            if (!$this->buffer->isUsable()) {
                return false;
            }

            // The IP lives in this local only: matched, then forgotten.
            $match = $this->ranges->verify($this->ipResolver->resolve($request));

            return $this->buffer->append(Event::site(
                Uuid::v4(),
                $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                $path,
                $userAgent,
                $response->getStatusCode(),
                self::bytes($request, $response),
                $match['verified'],
                $match['vendor'],
            ));
        } catch (\Throwable) {
            return false;
        }
    }
}
