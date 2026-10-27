<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The AI crawler registry robots.txt names, synced from Globetrotters so a
 * crawler added upstream reaches every install without a bundle release.
 *
 * Arrives in the same ``<ingest endpoint>/ranges`` document as the vendor
 * ranges (its ``aiUserAgents`` member, ranges contract v6), and is written by
 * the same daily fetch ({@see VendorRanges::refresh()}) — to its **own**
 * small file, ``ai-user-agents.json`` in ``reporting.buffer_dir``, so a
 * robots.txt request never loads or decodes the range list. This class
 * depends on the directory and the clock only.
 *
 * Each entry is ``{name, fallbackGroup?}`` in the order robots.txt emits
 * them. ``fallbackGroup`` names an agent whose group this one reads when the
 * site's file names none of its own (Applebot reads Googlebot's).
 *
 * A name lands verbatim on a ``User-agent:`` line, so both fields must be a
 * product token ({@see TOKEN_PATTERN}, the grammar {@see \Globetrotters\AiPresenceBundle\Serving\RobotsPolicy}
 * reads); an invalid entry is skipped, never repaired, and a list over
 * {@see MAX_AGENTS} is refused whole.
 *
 * A list older than {@see STALE_AFTER_SECONDS}, missing, unreadable or empty
 * serves nothing: robots.txt then names the compiled registry
 * (``RobotsFilter::AI_BOTS``), byte for byte what it named before the sync
 * existed. Installs without reporting configured never sync and always use
 * it. Kept in step with ``gt-wordpress-plugin``.
 */
final class AiUserAgents implements ResetInterface
{
    public const FILE = 'ai-user-agents.json';

    /** The vendor ranges' rule: two weeks without a confirmed fetch. */
    public const STALE_AFTER_SECONDS = 14 * 86400;

    /** More entries than this, and the whole list is refused. */
    public const MAX_AGENTS = 64;

    /** A robots.txt product token. */
    public const TOKEN_PATTERN = '/^[A-Za-z_-]+$/D';

    /**
     * Read once per process, or per request in a long-running one
     * (kernel.reset), so a list another lane refreshed is picked up.
     *
     * @var array{version: string, fetched_at: int, agents: list<array{name: string, fallbackGroup?: string}>}|null
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
     * The synced registry, or null when robots.txt should use the compiled
     * one: none stored, stale, or nothing valid in it.
     *
     * @return non-empty-list<array{name: string, fallbackGroup?: string}>|null
     */
    public function agents(): ?array
    {
        $list = $this->stored();
        if (null === $list || [] === $list['agents'] || $this->now() - $list['fetched_at'] > self::STALE_AFTER_SECONDS) {
            return null;
        }

        return $list['agents'];
    }

    /**
     * Replace the stored list with a freshly fetched one, stamped now. False
     * when it could not be written; the previous list is then left as it was.
     *
     * @param list<array{name: string, fallbackGroup?: string}> $agents already {@see parse()}d
     */
    public function store(string $version, array $agents): bool
    {
        return $this->write(['version' => $version, 'fetched_at' => $this->now(), 'agents' => $agents]);
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
     */
    public function version(): ?string
    {
        return $this->stored()['version'] ?? null;
    }

    public function fetchedAt(): ?int
    {
        return $this->stored()['fetched_at'] ?? null;
    }

    public function isStale(): bool
    {
        return null === $this->agents();
    }

    public function count(): int
    {
        return \count($this->stored()['agents'] ?? []);
    }

    /**
     * The ``aiUserAgents`` member of the ranges document, keeping the valid
     * entries in order. Anything but a list of at most {@see MAX_AGENTS}
     * entries — a backend from before the registry joined the document
     * included — reads as an empty list.
     *
     * @return list<array{name: string, fallbackGroup?: string}>
     */
    public static function parse(mixed $list): array
    {
        if (!\is_array($list) || !array_is_list($list) || \count($list) > self::MAX_AGENTS) {
            return [];
        }

        $parsed = [];
        foreach ($list as $entry) {
            if (!\is_array($entry) || !self::isToken($entry['name'] ?? null)) {
                continue;
            }
            $agent = ['name' => $entry['name']];
            if (\array_key_exists('fallbackGroup', $entry)) {
                if (!self::isToken($entry['fallbackGroup'])) {
                    continue;
                }
                $agent['fallbackGroup'] = $entry['fallbackGroup'];
            }
            $parsed[] = $agent;
        }

        return $parsed;
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private static function isToken(mixed $value): bool
    {
        return \is_string($value) && 1 === preg_match(self::TOKEN_PATTERN, $value);
    }

    /**
     * @return array{version: string, fetched_at: int, agents: list<array{name: string, fallbackGroup?: string}>}|null
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
        ) {
            return null;
        }

        // Our own file, written only from an already-parsed list: parsed again
        // because a name reaches robots.txt verbatim, and a hand-edited file
        // must not put a rule there.
        return $this->loaded = [
            'version' => $decoded['version'],
            'fetched_at' => $decoded['fetched_at'],
            'agents' => self::parse($decoded['agents'] ?? null),
        ];
    }

    /**
     * Written beside a temporary file and renamed over the old one, so a
     * robots.txt request reading it never sees half a list.
     *
     * @param array{version: string, fetched_at: int, agents: list<array{name: string, fallbackGroup?: string}>} $list
     */
    private function write(array $list): bool
    {
        if (!$this->directory->ensure()) {
            return false;
        }
        $json = json_encode($list, \JSON_UNESCAPED_SLASHES);
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
