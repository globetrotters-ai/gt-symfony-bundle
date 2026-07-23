<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Sync;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Client\FetcherInterface;
use Globetrotters\AiPresenceBundle\Serving\ContentTypes;
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

        $contentHash = $this->contentHash($files);
        $marker = $this->resolveVersionMarker($baseUrl, $contentHash);

        $files[ContentTypes::VERSION_MARKER] = $marker['body'];

        // Change detection keys off the stable content hash, not the version
        // string: when Globetrotters serves no marker the version is
        // synthesized from a timestamp (see resolveVersionMarker) and would
        // differ on every run, reporting a spurious "changed" even for
        // byte-identical content.
        $previousHash = (string) $this->options->state()['content_hash'];
        $this->cache->store($files, $marker['version'], $this->clock->now()->getTimestamp());

        $this->options->updateState([
            'installed_version' => $marker['version'],
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

        $result = $this->client->fetch($baseUrl.'/'.ContentTypes::VERSION_MARKER);
        if (!$result->isOk() || \strlen($result->body()) > FetcherInterface::MAX_BODY_BYTES) {
            return '';
        }

        $decoded = json_decode($result->body(), true);
        if (\is_array($decoded) && isset($decoded['version'])) {
            return (string) $decoded['version'];
        }

        return '';
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
     * @return array{body: string, version: string}
     */
    private function resolveVersionMarker(string $baseUrl, string $contentHash): array
    {
        $result = $this->client->fetch($baseUrl.'/'.ContentTypes::VERSION_MARKER);
        // Ignore an oversize (transport-truncated) marker and synthesize
        // instead, mirroring the required-files size guard in run().
        if ($result->isOk() && \strlen($result->body()) <= FetcherInterface::MAX_BODY_BYTES) {
            $decoded = json_decode($result->body(), true);
            if (\is_array($decoded) && isset($decoded['version'])) {
                return [
                    'body' => $result->body(),
                    'version' => (string) $decoded['version'],
                ];
            }
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

        return ['body' => $body, 'version' => $version];
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
