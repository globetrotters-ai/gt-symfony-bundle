<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Sync;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Client\FetcherInterface;
use Globetrotters\AiPresenceBundle\Client\FetchResult;
use Globetrotters\AiPresenceBundle\Serving\ContentTypes;
use Globetrotters\AiPresenceBundle\Serving\IndexNowKey;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Symfony\Component\Clock\ClockInterface;

/**
 * One refresh: fetch the full required artefact set and only overwrite the
 * cache when every required file fetched successfully, so a partial upstream
 * never replaces a good bundle.
 */
final class ArtefactSync
{
    public function __construct(
        private readonly FetcherInterface $client,
        private readonly ArtefactCache $cache,
        private readonly Options $options,
        private readonly ClockInterface $clock,
    ) {
    }

    public function run(): SyncResult
    {
        $baseUrl = $this->options->baseUrl();
        if ('' === $baseUrl) {
            return $this->fail(['No destination is connected yet.']);
        }

        $files = [];
        $errors = [];
        foreach ($this->requiredPaths() as $path) {
            $result = $this->client->fetch($baseUrl.'/'.$path);
            if (!$result->isOk()) {
                $reason = $result->isTransportError() ? $result->errorMessage() : (string) $result->status();
                $errors[] = $this->describeFailure($path, $reason);
                continue;
            }
            if (\strlen($result->body()) > FetcherInterface::MAX_BODY_BYTES) {
                $errors[] = $this->describeFailure($path, 'response body exceeds the size limit');
                continue;
            }
            $files[$path] = $result->body();
        }

        // All-or-nothing: any single failure aborts and leaves the stale bundle.
        if ([] !== $errors) {
            return $this->fail($errors);
        }

        // An individual artefact may legitimately be empty, but a well-behaved
        // origin never serves *every* required file empty at once. An all-empty
        // pull means a broken origin/proxy answering 200 with no body, so refuse
        // it rather than replace the last good bundle with blanks.
        if ([] !== $files && '' === implode('', $files)) {
            return $this->fail(['Every artefact came back empty; keeping the last good bundle.']);
        }

        $contentHash = $this->contentHash($files);
        $marker = $this->resolveVersionMarker($baseUrl, $contentHash);

        $files[ContentTypes::VERSION_MARKER] = $marker['body'];

        // Change detection keys off the stable content hash, not the version
        // string: when Globetrotters serves no marker the version is
        // synthesized from a timestamp (see resolveVersionMarker) and would
        // differ on every run, reporting a spurious "changed" even for
        // byte-identical content.
        $previousHash = (string) $this->options->state()['content_hash'];
        $reason = '';
        try {
            $stored = $this->cache->store($files, $marker['version'], $this->clock->now()->getTimestamp());
        } catch (\Throwable $error) {
            // Keep the backend's own message: a misconfigured or unreachable
            // cache pool is otherwise indistinguishable from one that simply
            // refused the write, and both surface as the same bare failure.
            $stored = false;
            $reason = $error->getMessage();
        }
        if (!$stored) {
            $detail = '' !== $reason ? \sprintf(' (%s)', $reason) : '';

            return $this->fail([\sprintf('Failed to persist refreshed artefacts%s; keeping the last good bundle.', $detail)]);
        }

        $this->options->updateState([
            'installed_version' => $marker['version'],
            // Written on every successful pull, including when the marker
            // carries no key: a key that has been rotated away upstream must
            // stop being served here rather than linger from an earlier sync.
            // A *failed* pull writes nothing, so the last known key keeps
            // serving alongside the last known good bundle.
            'indexnow_key' => $marker['indexnow_key'],
            // After a successful pull the installed bundle *is* the latest we
            // know of, so keep them in lockstep; they only diverge when a
            // later checkLatest() finds a newer upstream marker.
            'latest_version' => $marker['version'],
            'content_hash' => $contentHash,
            'last_refresh' => $this->clock->now()->getTimestamp(),
            'last_error' => '',
        ]);

        return new SyncResult(true, $previousHash !== $contentHash, $marker['version'], []);
    }

    /**
     * Look up the latest version Globetrotters advertises, without pulling.
     *
     * Reads the upstream drift marker when one is served; returns an empty
     * string when it isn't (in which case drift can't be detected ahead of a
     * pull, but every refresh still self-heals it).
     */
    public function checkLatest(): string
    {
        $baseUrl = $this->options->baseUrl();
        if ('' === $baseUrl) {
            return '';
        }

        return $this->markerVersion($this->client->fetch($baseUrl.'/'.ContentTypes::VERSION_MARKER)) ?? '';
    }

    /**
     * @return list<string>
     */
    private function requiredPaths(): array
    {
        return array_values(array_filter(
            ContentTypes::paths(),
            static fn (string $path): bool => ContentTypes::VERSION_MARKER !== $path,
        ));
    }

    /**
     * Prefer the marker Globetrotters serves (verbatim body + its version);
     * synthesize one when absent or invalid.
     *
     * ``indexnow_key`` is this environment's IndexNow key when the marker
     * carries one. It rides on the marker rather than being a fetched artefact
     * of its own: a required path that 404s in every keyless environment would
     * fail the whole sync there (see ``run()``), and a bundle *file* would have
     * to be excluded from ``contentHash`` in three byte-matched
     * implementations. The marker is already both synced and outside the hash.
     *
     * @return array{body: string, version: string, indexnow_key: string}
     */
    private function resolveVersionMarker(string $baseUrl, string $contentHash): array
    {
        // Prefer the marker Globetrotters serves verbatim; an unreachable,
        // oversize (transport-truncated), non-JSON or version-less marker
        // yields null and we synthesize instead.
        $result = $this->client->fetch($baseUrl.'/'.ContentTypes::VERSION_MARKER);
        $decoded = $this->decodeMarker($result);
        if (null !== $decoded) {
            return [
                'body' => $result->body(),
                'version' => (string) $decoded['version'],
                // Untrusted like every other byte from this origin, and the
                // value decides which path the router answers: anything outside
                // IndexNow's own key grammar reads as no key at all.
                'indexnow_key' => IndexNowKey::sanitize($decoded['indexnowKey'] ?? ''),
            ];
        }

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $version = $now->format('Y-m-d-His');
        $body = (string) json_encode([
            'generator' => 'globetrotters-apex-symfony-bundle',
            'destinationSlug' => $this->options->slug(),
            'version' => $version,
            'contentHash' => $contentHash,
            'builtAt' => $now->format('c'),
            'source' => 'synthesized',
        ]);

        return [
            'body' => $body,
            'version' => $version,
            // A synthesized marker is what an origin serving no marker
            // produces, so there is nothing to learn a key from.
            'indexnow_key' => '',
        ];
    }

    /**
     * Extract the version from a fetched version marker.
     */
    private function markerVersion(FetchResult $result): ?string
    {
        $decoded = $this->decodeMarker($result);

        return null === $decoded ? null : (string) $decoded['version'];
    }

    /**
     * Decode a fetched version marker, honouring the same size cap as the
     * required files. Returns null when the marker is unreachable, oversize,
     * not JSON, or carries no version — the single place the marker-parsing
     * contract lives.
     *
     * @return array<string, mixed>|null
     */
    private function decodeMarker(FetchResult $result): ?array
    {
        if (!$result->isOk() || \strlen($result->body()) > FetcherInterface::MAX_BODY_BYTES) {
            return null;
        }

        $decoded = json_decode($result->body(), true);

        return \is_array($decoded) && isset($decoded['version']) ? $decoded : null;
    }

    /**
     * Canonical content hash over the required files.
     *
     * Matches the backend ``canonical_hash`` byte-for-byte: sha256 over
     * lexicographically sorted ``path \0 raw-sha256(body) \0`` triples (raw
     * 32-byte inner digests, not hex), excluding the version marker.
     *
     * @param array<string, string> $files Apex-relative path → body
     */
    private function contentHash(array $files): string
    {
        ksort($files, \SORT_STRING);
        $parts = '';
        foreach ($files as $path => $body) {
            $parts .= $path."\0".hash('sha256', $body, true)."\0";
        }

        return hash('sha256', $parts);
    }

    /**
     * @param list<string> $errors
     */
    private function fail(array $errors): SyncResult
    {
        $this->options->updateState(['last_error' => implode('; ', $errors)]);

        return new SyncResult(false, false, $this->cache->version(), $errors);
    }

    private function describeFailure(string $path, string $reason): string
    {
        return \sprintf('Failed to fetch /%s (%s).', $path, $reason);
    }
}
