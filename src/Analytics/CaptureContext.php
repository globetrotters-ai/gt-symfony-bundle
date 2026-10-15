<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ``captureContext`` block every flush envelope carries, heartbeat
 * included: whether site capture is on, what may sit in front of this
 * application and hide requests from it, and which vendor range list the
 * ``verified`` flags were computed against. The backend derives the
 * "complete" or "floor" confidence badge from it.
 *
 * CDN markers and the page cache are observed on incoming requests and kept as
 * a sticky set in {@see AnalyticsState}, written only when something new is
 * seen, so the common case costs one memoized read. The set is cleared after
 * each accepted flush and rebuilt from the traffic that follows.
 */
final class CaptureContext
{
    /**
     * Request headers that betray a CDN or caching proxy, by the lowercase
     * name the contract reports.
     */
    public const CDN_MARKERS = [
        'cf-ray',
        'x-varnish',
        'via',
        'x-cache',
        'x-fastly-request-id',
        'x-akamai-transformed',
        'x-sucuri-id',
        'x-served-by',
    ];

    /**
     * The kernel runs behind Symfony's HttpCache: its hits never reach the
     * kernel, so never reach capture either.
     */
    public const PAGE_CACHE_SYMFONY = 'symfony-http-cache';

    public function __construct(
        private readonly AnalyticsOptions $options,
        private readonly AnalyticsState $state,
        private readonly ?VendorRanges $ranges = null,
    ) {
    }

    /**
     * Note the markers on one incoming request. Never throws.
     */
    public function observe(Request $request, ?Response $response = null): void
    {
        try {
            $seen = [];
            foreach (self::CDN_MARKERS as $marker) {
                if ($request->headers->has($marker)) {
                    $seen[] = $marker;
                }
            }
            $pageCache = self::detectPageCache($request, $response);

            $state = $this->state->state();
            $known = self::markers($state['cdn_markers'] ?? []);
            $merged = self::markers(array_merge($known, $seen));
            $storedCache = \is_string($state['page_cache'] ?? null) ? $state['page_cache'] : null;

            $update = [];
            if ($merged !== $known) {
                $update['cdn_markers'] = $merged;
            }
            if (null !== $pageCache && $pageCache !== $storedCache) {
                $update['page_cache'] = $pageCache;
            }
            if ([] !== $update) {
                $this->state->update($update);
            }
        } catch (\Throwable) {
            // Diagnostic only; never worth a served response.
        }
    }

    /**
     * The envelope block.
     *
     * @return array{siteCapture: bool, pageCache: string|null, cdnMarkers: list<string>, rangesVersion: string|null, rangesFetchedAt: string|null}
     */
    public function toArray(): array
    {
        $state = $this->state->state();

        return [
            'siteCapture' => $this->options->isSiteCaptureEnabled(),
            'pageCache' => \is_string($state['page_cache'] ?? null) ? $state['page_cache'] : null,
            'cdnMarkers' => self::markers($state['cdn_markers'] ?? []),
            'rangesVersion' => $this->ranges?->version(),
            'rangesFetchedAt' => $this->ranges?->fetchedAtIso(),
        ];
    }

    /**
     * After an accepted flush: start a fresh observation window.
     *
     * Given the block that was sent, clears only what it reported, so a
     * marker first seen by a request while the flush was in flight survives
     * to the next envelope instead of being wiped unsent.
     *
     * @param array<string, mixed>|null $reported the block the accepted envelope carried
     */
    public function settle(?array $reported = null): void
    {
        $state = $this->state->state();
        $markers = self::markers($state['cdn_markers'] ?? []);
        $pageCache = \is_string($state['page_cache'] ?? null) ? $state['page_cache'] : null;
        if ([] === $markers && null === $pageCache) {
            return;
        }

        $sentMarkers = null === $reported ? $markers : self::markers($reported['cdnMarkers'] ?? []);
        $sentCache = null === $reported ? $pageCache : ($reported['pageCache'] ?? null);

        $this->state->update([
            'cdn_markers' => array_values(array_diff($markers, $sentMarkers)),
            'page_cache' => $pageCache === $sentCache ? null : $pageCache,
        ]);
    }

    public static function detectPageCache(Request $request, ?Response $response): ?string
    {
        // HttpCache advertises ESI to the kernel it forwards to (with its
        // default surrogate), and with tracing on stamps X-Symfony-Cache.
        $capability = (string) $request->headers->get('Surrogate-Capability', '');
        if (str_contains($capability, 'symfony=') || (null !== $response && $response->headers->has('X-Symfony-Cache'))) {
            return self::PAGE_CACHE_SYMFONY;
        }

        return null;
    }

    /**
     * Known marker names only, deduplicated, in contract order.
     *
     * @return list<string>
     */
    private static function markers(mixed $value): array
    {
        $given = \is_array($value) ? $value : [];

        return array_values(array_filter(self::CDN_MARKERS, static fn (string $marker): bool => \in_array($marker, $given, true)));
    }
}
