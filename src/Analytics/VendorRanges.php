<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

use Symfony\Component\Clock\ClockInterface;

/**
 * The AI vendors' published crawler address ranges, synced from Globetrotters
 * and matched locally so a site event can say whether it came from a
 * verified vendor address without the address ever being stored.
 *
 * Fetched from ``<ingest endpoint>/ranges`` with the ingest token, at most once
 * a day, from the hourly refresh lanes (``gt:refresh`` and the Scheduler's
 * RefreshMessage), independently of the artefact sync: it needs no published
 * bundle. ``If-None-Match`` makes the daily check a 304 almost every time. A
 * failed fetch keeps the previous list.
 *
 * Stored as ``vendor-ranges.json`` in ``reporting.buffer_dir`` rather than in
 * the PSR-6 pool: ``cache:clear`` and most deploys empty ``var/cache`` (and with
 * it a filesystem ``cache.app``), which would leave every bot event unverified
 * until the next daily fetch. ``buffer_dir`` already has to survive deploys for
 * the event log, every lane already writes it, and the file is replaced by
 * ``rename()`` so a reader never sees half of it.
 *
 * A list older than {@see STALE_AFTER_SECONDS} (or none) answers ``null``:
 * stale data degrades verification, never capture.
 */
final class VendorRanges
{
    public const FILE = 'vendor-ranges.json';

    public const STALE_AFTER_SECONDS = 14 * 86400;

    public const CHECK_INTERVAL_SECONDS = 86400;

    /** AnalyticsState key: when a fetch was last attempted, whatever its outcome. */
    public const STATE_CHECKED_AT = 'ranges_checked_at';

    /** AnalyticsState key: the last fetch error, '' after a success. */
    public const STATE_ERROR = 'ranges_error';

    /**
     * Parsed once per process.
     *
     * @var array{version: string, fetched_at: int, ranges: list<array{platform: string, start: string, end: string}>}|null
     */
    private ?array $loaded = null;

    /**
     * The loaded ranges as binary start/end pairs, built on first match.
     *
     * @var list<array{0: string, 1: string, 2: string}>|null
     */
    private ?array $packed = null;

    public function __construct(
        private readonly BufferDirectory $directory,
        private readonly IngestTransportInterface $transport,
        private readonly AnalyticsOptions $options,
        private readonly AnalyticsState $state,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * ``<endpoint>/ranges``, or '' while reporting is not configured.
     */
    public function url(): string
    {
        $endpoint = $this->options->endpoint();

        return '' === $endpoint ? '' : rtrim($endpoint, '/').'/ranges';
    }

    /**
     * Whether a day has passed since the last attempt.
     */
    public function isDue(): bool
    {
        $checked = (int) ($this->state->state()[self::STATE_CHECKED_AT] ?? 0);

        return $this->now() - $checked >= self::CHECK_INTERVAL_SECONDS;
    }

    /**
     * Fetch the list when due. Never throws; returns whether a fetch was
     * attempted.
     */
    public function refreshIfDue(bool $force = false): bool
    {
        try {
            if (!$this->options->isConfigured() || (!$force && !$this->isDue())) {
                return false;
            }
            $this->refresh();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * One conditional fetch. On a 200 the new list replaces the old; on a 304
     * the old one is re-stamped as fresh; on anything else it is kept as is.
     */
    public function refresh(): void
    {
        $current = $this->stored();
        $result = $this->transport->fetchRanges(
            $this->url(),
            $this->options->ingestToken(),
            null === $current ? '' : $current['version'],
        );

        $error = '';
        if ($result->isNotModified() && null !== $current) {
            $current['fetched_at'] = $this->now();
            $this->write($current);
        } elseif ($result->isOk()) {
            $parsed = self::parse($result->body());
            if (null === $parsed) {
                $error = 'the range list could not be read';
            } else {
                $this->write(['version' => $parsed['version'], 'fetched_at' => $this->now(), 'ranges' => $parsed['ranges']]);
            }
        } else {
            $error = '' !== $result->errorMessage() ? $result->errorMessage() : 'unexpected response';
        }

        $this->state->update([self::STATE_CHECKED_AT => $this->now(), self::STATE_ERROR => $error]);
    }

    /**
     * Verification for one client IP.
     *
     * @return array{verified: bool|null, vendor: string|null}
     */
    public function verify(string $ip): array
    {
        $list = $this->fresh();
        if (null === $list) {
            return ['verified' => null, 'vendor' => null];
        }

        $packed = '' === $ip ? false : @inet_pton($ip);
        if (false === $packed) {
            // A list we trust, and no address to match against it: not in any
            // range is the honest answer.
            return ['verified' => false, 'vendor' => null];
        }

        if (null === $this->packed) {
            $this->packed = [];
            foreach ($list['ranges'] as $range) {
                $start = @inet_pton($range['start']);
                $end = @inet_pton($range['end']);
                if (false !== $start && false !== $end && \strlen($start) === \strlen($end)) {
                    $this->packed[] = [$start, $end, $range['platform']];
                }
            }
        }

        $length = \strlen($packed);
        foreach ($this->packed as [$start, $end, $platform]) {
            // Same-length network-order binary strings compare as numbers, so
            // IPv4 and IPv6 are both a plain byte comparison.
            if (\strlen($start) === $length && strcmp($packed, $start) >= 0 && strcmp($packed, $end) <= 0) {
                return ['verified' => true, 'vendor' => $platform];
            }
        }

        return ['verified' => false, 'vendor' => null];
    }

    public function version(): ?string
    {
        return $this->stored()['version'] ?? null;
    }

    public function fetchedAt(): ?int
    {
        return $this->stored()['fetched_at'] ?? null;
    }

    /**
     * ``fetched_at`` as ISO 8601 UTC, for the capture context.
     */
    public function fetchedAtIso(): ?string
    {
        $at = $this->fetchedAt();

        return null === $at ? null : gmdate('Y-m-d\TH:i:s\Z', $at);
    }

    public function count(): int
    {
        return \count($this->stored()['ranges'] ?? []);
    }

    public function isStale(): bool
    {
        return null === $this->fresh();
    }

    public function lastError(): string
    {
        return (string) ($this->state->state()[self::STATE_ERROR] ?? '');
    }

    /**
     * @return array{version: string, fetched_at: int, ranges: list<array{platform: string, start: string, end: string}>}|null
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
     * @return array{version: string, fetched_at: int, ranges: list<array{platform: string, start: string, end: string}>}|null
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
        if (!\is_array($decoded) || !\is_string($decoded['version'] ?? null) || !\is_int($decoded['fetched_at'] ?? null)) {
            return null;
        }
        $parsed = self::parse($raw);
        if (null === $parsed) {
            return null;
        }

        return $this->loaded = ['version' => $parsed['version'], 'fetched_at' => $decoded['fetched_at'], 'ranges' => $parsed['ranges']];
    }

    /**
     * @param array{version: string, fetched_at: int, ranges: list<array{platform: string, start: string, end: string}>} $list
     */
    private function write(array $list): void
    {
        if (!$this->directory->ensure()) {
            return;
        }
        $json = json_encode($list, \JSON_UNESCAPED_SLASHES);
        if (!\is_string($json)) {
            return;
        }

        $path = $this->directory->path(self::FILE);
        $temp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (false === @file_put_contents($temp, $json)) {
            return;
        }
        $this->directory->share($temp);
        if (!@rename($temp, $path)) {
            @unlink($temp);

            return;
        }
        $this->loaded = $list;
        $this->packed = null;
    }

    /**
     * The wire (or stored) list, keeping only well-formed ranges.
     *
     * @return array{version: string, ranges: list<array{platform: string, start: string, end: string}>}|null
     */
    private static function parse(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (!\is_array($decoded) || !\is_string($decoded['version'] ?? null) || '' === $decoded['version'] || !\is_array($decoded['ranges'] ?? null)) {
            return null;
        }

        $ranges = [];
        foreach ($decoded['ranges'] as $range) {
            if (!\is_array($range)) {
                continue;
            }
            $platform = $range['platform'] ?? null;
            $start = $range['start'] ?? null;
            $end = $range['end'] ?? null;
            if (!\is_string($platform) || '' === $platform || !\is_string($start) || !\is_string($end)) {
                continue;
            }
            if (false === @inet_pton($start) || false === @inet_pton($end)) {
                continue;
            }
            $ranges[] = ['platform' => $platform, 'start' => $start, 'end' => $end];
        }

        return ['version' => $decoded['version'], 'ranges' => $ranges];
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
