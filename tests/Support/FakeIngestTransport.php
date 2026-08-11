<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Support;

use Globetrotters\AiPresenceBundle\Analytics\IngestResult;
use Globetrotters\AiPresenceBundle\Analytics\IngestTransportInterface;

/**
 * Canned ingest transport, so the flusher's batching, retry and drop
 * accounting can be tested without live HTTP. Mirrors {@see FakeFetcher} on
 * the pull side.
 */
final class FakeIngestTransport implements IngestTransportInterface
{
    /** @var list<array{url: string, token: string, json: string}> */
    public array $sent = [];

    /** @var list<IngestResult> */
    private array $queued = [];

    private IngestResult $fallback;

    private ?int $wireCap = null;

    /**
     * Compression ratio applied by {@see wireSize()}; 1 means "no gzip".
     */
    private int $compressionRatio = 1;

    public function __construct()
    {
        $this->fallback = IngestResult::http(202);
    }

    public function willReturn(IngestResult ...$results): self
    {
        $this->queued = array_values($results);

        return $this;
    }

    public function fallback(IngestResult $result): self
    {
        $this->fallback = $result;

        return $this;
    }

    public function capAt(int $bytes, int $compressionRatio = 1): self
    {
        $this->wireCap = $bytes;
        $this->compressionRatio = max(1, $compressionRatio);

        return $this;
    }

    public function post(string $url, string $token, string $json): IngestResult
    {
        $this->sent[] = ['url' => $url, 'token' => $token, 'json' => $json];

        return array_shift($this->queued) ?? $this->fallback;
    }

    public function wireSize(string $json): int
    {
        return intdiv(\strlen($json), $this->compressionRatio);
    }

    public function wireCap(): int
    {
        return $this->wireCap ?? self::MAX_BODY_BYTES;
    }

    /**
     * Decoded envelopes, in the order they were sent.
     *
     * @return list<array<string, mixed>>
     */
    public function envelopes(): array
    {
        return array_map(
            static function (array $sent): array {
                $decoded = json_decode($sent['json'], true);

                return \is_array($decoded) ? $decoded : [];
            },
            $this->sent,
        );
    }

    /**
     * Event ids carried by one sent envelope.
     *
     * @return list<string>
     */
    public function idsOf(int $index): array
    {
        $envelope = $this->envelopes()[$index] ?? [];
        $events = $envelope['events'] ?? [];
        if (!\is_array($events)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $event): string => \is_array($event) && \is_scalar($event['id'] ?? null) ? (string) $event['id'] : '',
            $events,
        ));
    }
}
