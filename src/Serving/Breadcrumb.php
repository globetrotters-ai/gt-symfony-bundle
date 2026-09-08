<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

/**
 * The breadcrumb markup for the ``subdomain_breadcrumb`` install profile, and
 * the derivation of the one value it needs: the canonical Globetrotters origin.
 *
 * ## Why the origin is derived rather than configured
 *
 * The offloaded artefacts are referenced by absolute URL, so the breadcrumb has
 * to name a host. That host is not stable: it is ``<slug>.globetrotters.ai``
 * until a custom hostname activates and ``ai.<their-domain>`` afterwards, and it
 * flips back on detach. A configured value would keep pointing at the old host
 * after either flip, silently, for as long as nobody noticed — the worst failure
 * mode available, because the stale link still resolves.
 *
 * So it is read from an artefact this bundle already pulls on every refresh, and
 * therefore re-derives itself on the normal refresh cycle with no extra request:
 * ``ai.json``'s ``metadata.relatedFiles[].url``. The backend builds those
 * absolute on ``canonical_origin``, which
 * ``website/services/publishing_service.py`` defines as "the active custom host
 * when one is set, else the slug host" — exactly the value needed here, kept
 * correct by the same code that decides it.
 *
 * The two candidates that do not work, so nobody re-litigates them:
 *
 * - The edge proxy's ``Link: <…>; rel="canonical"`` header is gated to HTML 200s
 *   (``apps/edge-proxy/app/main.py``, asserted by
 *   ``test_link_canonical_absent_on_non_html_artefact``). Every artefact this
 *   bundle fetches is text or JSON, so the header is never present on any of
 *   them, and {@see \Globetrotters\AiPresenceBundle\Client\FetchResult} carries
 *   no headers anyway.
 * - ``ai.json``'s own top-level ``url`` is the *customer's* website, not the
 *   host we publish to. Reading it would point the breadcrumb back at the apex
 *   it is emitted from.
 *
 * Only ``llms.txt`` and ``schema.json`` are consulted. The other two entries
 * (``llms-full.txt``, ``content.md``) are the offload pair, which on a bundle
 * built for a minimal-footprint profile legitimately point at a different host.
 */
final class Breadcrumb
{
    /**
     * Opens the injected head block. Matches the Studio's copy-paste snippet
     * verbatim (``serve-inbound-link.component.ts``) so a customer who pasted
     * the block by hand ends up with the same markup the bundle would inject.
     *
     * Not the double-injection guard — see {@see self::headGuard()}.
     */
    public const MARKER = '<!-- Globetrotters — AI presence -->';

    /**
     * Artefacts whose published URL always sits on the canonical origin, in
     * preference order. Never the offloaded pair.
     */
    private const ORIGIN_SOURCES = ['llms.txt', 'schema.json'];

    private const DEFAULT_ANCHOR_TEXT = 'Our AI presence';

    /**
     * The canonical Globetrotters origin (scheme, host and any non-default
     * port), or '' when the cached ai.json cannot supply one — an unparseable
     * body, or the relative links the disk backend emits outside production.
     * Callers treat '' as "emit no breadcrumb": a breadcrumb to nowhere is
     * worse than none.
     */
    public static function originFrom(?string $aiJson): string
    {
        $links = self::relatedFiles($aiJson);
        if ([] === $links) {
            return '';
        }

        foreach (self::ORIGIN_SOURCES as $name) {
            foreach ($links as $url) {
                if (!str_ends_with(self::pathOf($url), '/'.$name)) {
                    continue;
                }
                $origin = self::originOf($url);
                if ('' !== $origin) {
                    return $origin;
                }
            }
        }

        return '';
    }

    /**
     * Anchor text used when none is configured, mirroring the Studio block:
     * the destination name when ai.json carries one, a generic phrase
     * otherwise. English, because the bundle has no locale to work from — an
     * integrator whose site is not in English sets ``breadcrumb.anchor_text``.
     */
    public static function defaultAnchorText(?string $aiJson): string
    {
        $decoded = json_decode((string) $aiJson, true);
        $name = \is_array($decoded) ? ($decoded['name'] ?? null) : null;
        if (!\is_string($name) || '' === trim($name)) {
            return self::DEFAULT_ANCHOR_TEXT;
        }

        return \sprintf('AI presence for %s', trim($name));
    }

    /**
     * The head half of the breadcrumb: the JSON-LD alternate plus the
     * non-standard agent-discovery relations the published pages already emit
     * server-side. Byte-for-byte the Studio's snippet, so the two lanes cannot
     * drift into describing the same install differently.
     *
     * These are pointers, not the discovery signal. A crawler follows the
     * anchor; see {@see self::anchor()}.
     */
    public static function headBlock(string $origin): string
    {
        if ('' === $origin) {
            return '';
        }
        $base = self::escape($origin);

        return implode("\n", [
            self::MARKER,
            \sprintf('<link rel="alternate" type="application/ld+json" href="%s/schema.json">', $base),
            \sprintf('<link rel="ai-catalog" href="%s/.well-known/ai-catalog.json">', $base),
            \sprintf('<link rel="mcp" href="%s/.well-known/mcp.json">', $base),
            \sprintf('<link rel="agent-card" href="%s/.well-known/agent-card.json">', $base),
        ])."\n";
    }

    /**
     * The visible footer anchor — the load-bearing half. A real ``<a href>`` is
     * what a crawler follows and what carries a discovery signal; the ``<link>``
     * relations above do not. A subdomain inherits none of the apex's index
     * membership or authority, so without this the published host is reachable
     * only by something that already knows its name.
     *
     * Both arguments are escaped: the text is integrator-supplied config and the
     * origin comes from a fetched artefact, and both land in someone's live
     * homepage.
     */
    public static function anchor(string $origin, string $text): string
    {
        $text = trim($text);
        if ('' === $origin || '' === $text) {
            return '';
        }

        return \sprintf('<a href="%s">%s</a>', self::escape($origin), self::escape($text))."\n";
    }

    /**
     * What "this page already carries the head block" looks like.
     *
     * Deliberately a ``<link>`` relation rather than {@see self::MARKER}: the
     * marker is an HTML comment, and stripping comments is what an HTML
     * minifier does by default — so keying the guard on it would fail in
     * exactly the case the guard exists for, and inject a second copy of every
     * relation. ``rel="ai-catalog"`` survives minification and appears nowhere
     * else in this bundle's output.
     *
     * Origin-independent on purpose: after a custom-hostname flip a hand-placed
     * block still names the old host, and a second block for the new one is
     * worse than one stale block, which the next refresh's own injection cannot
     * fix anyway.
     *
     * @return list<string>
     */
    public static function headGuards(): array
    {
        return ['rel="ai-catalog"'];
    }

    /**
     * What "this page already links back" looks like, for the injector's
     * idempotency check: any anchor to the canonical origin, whatever its text.
     * Deliberately not the full anchor — the text can legitimately differ (a
     * hand-placed Twig call made before ``anchor_text`` was configured, or
     * before the destination was named), and a second anchor to the same host
     * is the one outcome worth preventing.
     *
     * Both the bare origin and its trailing-slash form count, because a
     * hand-written link to a host root very often carries the slash. The
     * closing quote stays in each variant: without it, ``https://ai.nantes.fr``
     * would also match ``https://ai.nantes.fr.example.test`` and silently
     * suppress a legitimate anchor.
     *
     * @return list<string>
     */
    public static function anchorGuards(string $origin): array
    {
        if ('' === $origin) {
            return [];
        }
        $escaped = self::escape($origin);

        return [
            \sprintf('<a href="%s"', $escaped),
            \sprintf('<a href="%s/"', $escaped),
        ];
    }

    /**
     * @return list<string> every relatedFiles URL, in published order
     */
    private static function relatedFiles(?string $aiJson): array
    {
        $decoded = json_decode((string) $aiJson, true);
        if (!\is_array($decoded)) {
            return [];
        }
        $metadata = $decoded['metadata'] ?? null;
        $related = \is_array($metadata) ? ($metadata['relatedFiles'] ?? null) : null;
        if (!\is_array($related)) {
            return [];
        }

        $urls = [];
        foreach ($related as $entry) {
            $url = \is_array($entry) ? ($entry['url'] ?? null) : null;
            if (\is_string($url) && '' !== $url) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private static function pathOf(string $url): string
    {
        $path = parse_url($url, \PHP_URL_PATH);

        return \is_string($path) ? $path : '';
    }

    /**
     * scheme://host[:port] for an absolute http(s) URL, '' for anything else.
     * The scheme allow-list matters: this value is interpolated into an href,
     * so a ``javascript:`` or ``data:`` URL smuggled through a compromised or
     * misconfigured origin must never reach the customer's markup.
     */
    private static function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts)) {
            return '';
        }
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        if (!\in_array($scheme, ['http', 'https'], true) || '' === $host) {
            return '';
        }
        $port = $parts['port'] ?? null;
        $suffix = \is_int($port) ? ':'.$port : '';

        return $scheme.'://'.$host.$suffix;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
