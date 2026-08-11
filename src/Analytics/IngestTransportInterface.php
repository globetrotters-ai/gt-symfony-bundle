<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

/**
 * Sends one JSON envelope to the server-log ingest endpoint.
 *
 * Extracted so {@see Flusher} — which owns batching, retry and the drop
 * accounting — can be unit-tested against a canned transport, mirroring
 * {@see \Globetrotters\AiPresenceBundle\Client\FetcherInterface} on the pull
 * side.
 *
 * The size caps live here rather than in the flusher because only the transport
 * knows whether a body will actually be compressed, and the backend applies a
 * different wire cap to each case.
 */
interface IngestTransportInterface
{
    /**
     * Largest batch the backend accepts. Excess events are discarded
     * server-side, so a conforming producer splits instead and keeps the
     * remainder buffered.
     */
    public const MAX_EVENTS_PER_BATCH = 1000;

    /**
     * Largest decompressed body the backend accepts, in bytes.
     */
    public const MAX_BODY_BYTES = 262144;

    /**
     * Largest gzipped body the backend accepts, in bytes. Tighter than the
     * decompressed cap: a compressed body is refused before inflation, so a
     * zip bomb never costs the backend the expansion.
     */
    public const MAX_COMPRESSED_BODY_BYTES = 65536;

    /**
     * POST one envelope.
     *
     * @param string $url   ingest endpoint URL, as issued alongside the token
     * @param string $token raw ingest token, sent as a Bearer credential
     * @param string $json  encoded envelope
     */
    public function post(string $url, string $token, string $json): IngestResult;

    /**
     * Size of the envelope as it would go on the wire, in bytes.
     */
    public function wireSize(string $json): int;

    /**
     * The backend cap that applies to the wire form this transport produces.
     */
    public function wireCap(): int;
}
