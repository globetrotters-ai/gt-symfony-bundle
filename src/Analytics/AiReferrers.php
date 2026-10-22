<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The hosts and ``utm_source`` values AI assistants send their visitors from,
 * synced from Globetrotters so the page-view counter can say which platform
 * referred a human view without the referrer itself ever being kept, and the
 * search hosts behind which a ``utm_source`` is not trusted.
 *
 * Arrives in the same ``<ingest endpoint>/ranges`` document as the vendor
 * ranges (its ``aiReferrers`` block), and is written by the same daily fetch
 * ({@see VendorRanges::refresh()}) — but to its **own** small file,
 * ``ai-referrers.json`` in ``reporting.buffer_dir``. The beacon endpoint is
 * the hottest path the reporting lane has, one request per human page view;
 * it reads this ~1 KB file, and only when the beacon carries something to
 * match, and never loads or decodes the 100 KB range list. This class depends
 * on the directory and the clock only, so nothing on that path can reach the
 * ranges.
 *
 * Only the matched platform leaves {@see match()}. The host and the
 * ``utm_source`` value are normalised and looked up in memory, then dropped:
 * never written, logged or sent.
 *
 * A list older than {@see STALE_AFTER_SECONDS}, missing or unreadable matches
 * nothing: the view is still counted, as ``none``. Stale data degrades
 * attribution, never counting. Kept in step with ``gt-wordpress-plugin``.
 */
final class AiReferrers implements ResetInterface
{
    public const FILE = 'ai-referrers.json';

    /** The vendor ranges' rule: two weeks without a confirmed fetch. */
    public const STALE_AFTER_SECONDS = 14 * 86400;

    /**
     * What ``<name>.*`` in a search host pattern stands for: one or two TLD
     * labels of two letters or more (gt-backend ``vendor_ranges._ANY_TLD``).
     */
    private const ANY_TLD = '\\.[a-z]{2,}(?:\\.[a-z]{2,})?';

    /**
     * Read once per process, or per request in a long-running one
     * (kernel.reset), so a list another lane refreshed is picked up.
     *
     * ``searchHosts`` is null for a file written before the key existed.
     *
     * @var array{version: string, fetched_at: int, hosts: array<string, string>, utmSources: array<string, string>, searchHosts: list<string>|null}|null
     */
    private ?array $loaded = null;

    public function __construct(
        private readonly BufferDirectory $directory,
        private readonly ClockInterface $clock,
    ) {
    }

    public function reset(): void
    {
        $this->loaded = null;
    }

    /**
     * The platform that referred a view, or ``none``.
     *
     * The host is looked up first and wins: it is what the browser reports,
     * while a ``utm_source`` is whatever the link carried, copied and shared
     * along with it. Exact matches only, after normalising — a subdomain or
     * parent domain of a listed host is a different site.
     *
     * The ``utm_source`` is looked up only when the host is empty or not a
     * search host: an assistant's tag on a page that a search engine indexed
     * travels with the search result, and that click is a search referral.
     *
     * With neither a host nor a ``utm_source`` — nearly every view — this
     * returns before touching the file.
     *
     * @param string $referrerHost the host of ``document.referrer``, as the script sent it
     * @param string $utmSource    the page's ``utm_source`` value, as the script sent it
     */
    public function match(string $referrerHost, string $utmSource): string
    {
        $host = self::normalizeHost($referrerHost);
        $source = self::normalizeUtmSource($utmSource);
        if ('' === $host && '' === $source) {
            return PageViewRules::PLATFORM_NONE;
        }

        $list = $this->fresh();
        if (null === $list) {
            return PageViewRules::PLATFORM_NONE;
        }

        if ('' !== $host && isset($list['hosts'][$host])) {
            return $list['hosts'][$host];
        }
        if ('' === $source || '' !== $host && self::isSearchHost($host, $list['searchHosts'] ?? [])) {
            return PageViewRules::PLATFORM_NONE;
        }

        return $list['utmSources'][$source] ?? PageViewRules::PLATFORM_NONE;
    }

    /**
     * Replace the stored list with a freshly fetched one, stamped now. False
     * when it could not be written; the previous list is then left as it was.
     *
     * @param array<string, string> $hosts
     * @param array<string, string> $utmSources
     * @param list<string>          $searchHosts
     */
    public function store(string $version, array $hosts, array $utmSources, array $searchHosts = []): bool
    {
        return $this->write([
            'version' => $version,
            'fetched_at' => $this->now(),
            'hosts' => $hosts,
            'utmSources' => $utmSources,
            'searchHosts' => $searchHosts,
        ]);
    }

    /**
     * Confirm the stored list as fresh (the ranges document answered ``304``).
     * False when none is stored or it could not be written.
     */
    public function restamp(): bool
    {
        $list = $this->stored();
        if (null === $list) {
            return false;
        }
        $list['fetched_at'] = $this->now();

        return $this->write($list);
    }

    /**
     * The ranges document version the stored list came from.
     *
     * Null for a list stored before ``searchHosts`` existed, though it still
     * matches: 0.10.x may have stored it at the very version that introduced
     * the key, and a conditional sync would then answer ``304`` until the
     * backend's version next moved. Null makes the next sync unconditional,
     * and its ``200`` brings the key.
     */
    public function version(): ?string
    {
        $list = $this->stored();

        return null === $list || null === $list['searchHosts'] ? null : $list['version'];
    }

    public function fetchedAt(): ?int
    {
        return $this->stored()['fetched_at'] ?? null;
    }

    public function isStale(): bool
    {
        return null === $this->fresh();
    }

    public function hostCount(): int
    {
        return \count($this->stored()['hosts'] ?? []);
    }

    public function utmSourceCount(): int
    {
        return \count($this->stored()['utmSources'] ?? []);
    }

    /**
     * The ``aiReferrers`` block of the ranges document, keeping only entries
     * that can be matched and counted: a non-empty key (lower-cased, as the
     * lookup normalises) mapped to a platform slug
     * ({@see PageViewRules::PLATFORM_PATTERN}) that is safe in a counter key and
     * on the wire. A block that is missing or not a pair of maps — a backend
     * from before the list existed — reads as two empty lists. ``searchHosts``
     * (since 2026-10-22) keeps its non-empty, lower-cased string patterns; a
     * backend from before it reads as an empty list, which matches nothing.
     *
     * @return array{hosts: array<string, string>, utmSources: array<string, string>, searchHosts: list<string>}
     */
    public static function parse(mixed $block): array
    {
        return [
            'hosts' => self::parseMap(\is_array($block) ? $block['hosts'] ?? null : null),
            'utmSources' => self::parseMap(\is_array($block) ? $block['utmSources'] ?? null : null),
            'searchHosts' => self::parseList(\is_array($block) ? $block['searchHosts'] ?? null : null),
        ];
    }

    /**
     * Lower-case, without a ``:port`` or the trailing dot of a fully
     * qualified name: ``ChatGPT.com.:443`` is ``chatgpt.com``.
     */
    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        // A port, but not the inside of a bracketed IPv6 literal.
        $host = (string) preg_replace('/(?<=[^:\]]|\]):\d*$/', '', $host);

        return rtrim($host, '.');
    }

    /**
     * Trimmed, lower-case, cut at the first ``?``: Copilot sends
     * ``copilot.com?form=MG0AUO``, which is ``copilot.com``.
     */
    private static function normalizeUtmSource(string $source): string
    {
        $source = strtolower(trim($source));
        $query = strpos($source, '?');

        return false === $query ? $source : substr($source, 0, $query);
    }

    /**
     * A bare pattern matches the host exactly; ``<name>.*`` matches
     * ``<name>.`` and one or two TLD labels, so ``google.*`` is ``google.fr``
     * and ``google.co.uk``, never ``gemini.google.com``. No pattern, no match.
     *
     * @param list<string> $patterns
     */
    private static function isSearchHost(string $host, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '.*')
                ? 1 === preg_match('/^'.preg_quote(substr($pattern, 0, -2), '/').self::ANY_TLD.'$/D', $host)
                : $pattern === $host
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private static function parseMap(mixed $map): array
    {
        if (!\is_array($map) || array_is_list($map) && [] !== $map) {
            return [];
        }

        $parsed = [];
        foreach ($map as $key => $platform) {
            $key = strtolower(trim((string) $key));
            if ('' !== $key && \is_string($platform) && 1 === preg_match(PageViewRules::PLATFORM_PATTERN, $platform)) {
                $parsed[$key] = $platform;
            }
        }

        return $parsed;
    }

    /**
     * @return list<string>
     */
    private static function parseList(mixed $list): array
    {
        if (!\is_array($list) || !array_is_list($list)) {
            return [];
        }

        $parsed = [];
        foreach ($list as $pattern) {
            if (\is_string($pattern) && '' !== $pattern = strtolower(trim($pattern))) {
                $parsed[] = $pattern;
            }
        }

        return $parsed;
    }

    /**
     * @return array{version: string, fetched_at: int, hosts: array<string, string>, utmSources: array<string, string>, searchHosts: list<string>|null}|null
     */
    private function fresh(): ?array
    {
        $list = $this->stored();
        if (null === $list || $this->now() - $list['fetched_at'] > self::STALE_AFTER_SECONDS) {
            return null;
        }

        return $list;
    }

    /**
     * @return array{version: string, fetched_at: int, hosts: array<string, string>, utmSources: array<string, string>, searchHosts: list<string>|null}|null
     */
    private function stored(): ?array
    {
        if (null !== $this->loaded) {
            return $this->loaded;
        }

        $raw = @file_get_contents($this->directory->path(self::FILE));
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)
            || !\is_string($decoded['version'] ?? null) || '' === $decoded['version']
            || !\is_int($decoded['fetched_at'] ?? null)
            || !\is_array($decoded['hosts'] ?? null)
            || !\is_array($decoded['utmSources'] ?? null)
        ) {
            return null;
        }

        // Our own file, written only from an already-parsed list: the parse
        // again is cheap at this size, and keeps a hand-edited file from
        // putting anything but a slug into a counter key. A file from 0.10.x
        // has no searchHosts: kept as null (none, and no version()), not
        // discarded.
        return $this->loaded = [
            'version' => $decoded['version'],
            'fetched_at' => $decoded['fetched_at'],
            'hosts' => self::parseMap($decoded['hosts']),
            'utmSources' => self::parseMap($decoded['utmSources']),
            'searchHosts' => \array_key_exists('searchHosts', $decoded) ? self::parseList($decoded['searchHosts']) : null,
        ];
    }

    /**
     * Written beside a temporary file and renamed over the old one, so the
     * beacon endpoint reading it never sees half a list.
     *
     * @param array{version: string, fetched_at: int, hosts: array<string, string>, utmSources: array<string, string>, searchHosts: list<string>|null} $list
     */
    private function write(array $list): bool
    {
        if (!$this->directory->ensure()) {
            return false;
        }
        // Objects, so an empty list is still {} and the file has one shape.
        // A restamp keeps a 0.10.x file without searchHosts as it was.
        $json = json_encode(
            [
                'version' => $list['version'],
                'fetched_at' => $list['fetched_at'],
                'hosts' => (object) $list['hosts'],
                'utmSources' => (object) $list['utmSources'],
            ] + (null === $list['searchHosts'] ? [] : ['searchHosts' => $list['searchHosts']]),
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
        if (!\is_string($json) || !$this->directory->replace($this->directory->path(self::FILE), $json)) {
            return false;
        }
        $this->loaded = $list;

        return true;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
