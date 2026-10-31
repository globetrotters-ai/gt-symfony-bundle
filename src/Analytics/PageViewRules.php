<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

/**
 * What the page-view counter accepts and how it buckets a visitor: the path
 * rules the backend applies at ingest, and the coarse User-Agent split.
 *
 * The path rules mirror the backend's so a record this install counts is one
 * the backend keeps — a record it would drop is better never counted, since it
 * would only take a slot under the 2000-paths-a-day cap. Kept in step with
 * ``gt-wordpress-plugin``.
 */
final class PageViewRules
{
    public const BUCKET_BROWSER = 'browser';
    public const BUCKET_AI_BROWSER = 'ai_browser';

    /**
     * The platform of a view no AI answer referred — and of every
     * ``ai_browser`` view, which says where it came from by its bucket. What
     * the backend stores a missing or unknown platform as, too.
     */
    public const PLATFORM_NONE = 'none';

    /**
     * What a platform may look like: the backend's agent platform slugs
     * (``chatgpt``, ``claude``, …). No tab, no ``@`` and nothing upper-case,
     * so one can never corrupt a counter key (``bucket@platform\tpath``).
     */
    public const PLATFORM_PATTERN = '/^[a-z0-9_-]{1,32}$/D';

    /**
     * Where increments go once a day already counts 2000 distinct paths. A
     * literal the backend accepts in place of a path, and one no client can
     * send (it does not start with ``/``).
     */
    public const OTHER_PATH = '(other)';

    public const MAX_PATH_LENGTH = 512;

    /**
     * An obvious automated client. Not counted at all: crawlers do not run the
     * script, so a beacon from one is a replay or a headless render, neither
     * of which is a human view. ``bot`` not preceded by ``cu``: CUBOT is a
     * phone maker, and its handsets name themselves in the User-Agent.
     */
    public const BOT_PATTERN = '/(?<!cu)bot|crawl|spider|slurp|fetch|python|curl|wget|go-http|headlesschrome|phantomjs|lighthouse|preview/i';

    /**
     * A browser that says it is driven by an AI assistant (ChatGPT agent,
     * Comet and similar): counted, in its own bucket.
     */
    public const AI_BROWSER_PATTERN = '/chatgpt|oai-|comet|perplexity|claude/i';

    /**
     * The path as the counter stores it, or null when the backend would drop
     * it.
     *
     * Refuses rather than repairs, as the backend does: a query string, a
     * fragment, anything not absolute, a protocol-relative ``//host``, more
     * than 512 characters, control characters or invalid UTF-8. A trailing
     * slash is folded (``/visiter/`` counts as ``/visiter``), except on the
     * root itself.
     */
    public static function normalizePath(string $path): ?string
    {
        if ('' === $path || '/' !== $path[0] || str_starts_with($path, '//')) {
            return null;
        }
        if (\strlen($path) > self::MAX_PATH_LENGTH) {
            return null;
        }
        if (str_contains($path, '?') || str_contains($path, '#')) {
            return null;
        }
        // preg_match() with the u modifier answers false on invalid UTF-8,
        // without needing ext-mbstring.
        if (0 !== preg_match('/[\x00-\x1F\x7F]/u', $path)) {
            return null;
        }

        $folded = rtrim($path, '/');

        return '' === $folded ? '/' : $folded;
    }

    /**
     * The bucket a User-Agent counts in, or null when it is not counted.
     *
     * The string is only ever matched here and then forgotten: the counter
     * stores the bucket, never the User-Agent.
     *
     * A beacon carrying a ``Signature-Agent`` header (``$signed``, contract
     * v7) counts as ``ai_browser`` whatever browser it claims to be: an agent
     * that signs, like Meta Muse, sends a stock browser User-Agent.
     * Unverified and coarse, like the pattern; a bot stays uncounted.
     */
    public static function bucket(string $userAgent, bool $signed = false): ?string
    {
        if ('' === trim($userAgent) || 1 === preg_match(self::BOT_PATTERN, $userAgent)) {
            return null;
        }

        return $signed || 1 === preg_match(self::AI_BROWSER_PATTERN, $userAgent)
            ? self::BUCKET_AI_BROWSER
            : self::BUCKET_BROWSER;
    }
}
