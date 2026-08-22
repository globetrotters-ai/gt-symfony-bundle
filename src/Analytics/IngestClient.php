<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

use Globetrotters\AiPresenceBundle\GlobetrottersAiPresenceBundle;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Symfony HttpClient transport for the server-log ingest endpoint.
 *
 * Gzips the body when zlib is available. Compression is optional in the
 * contract and the backend applies a different wire cap to each case — 64KB
 * compressed, 256KB uncompressed — so {@see wireCap()} reports whichever one
 * actually applies rather than assuming the tighter of the two.
 *
 * The injected client is the same SSRF-guarded one the pull side uses: the
 * endpoint is a Globetrotters host, but the value is pasted by hand.
 *
 * The token is passed as a Bearer credential and appears nowhere else — not in
 * a log line, not in an error message, not on the returned result.
 */
final class IngestClient implements IngestTransportInterface
{
    /**
     * Longer than the pull side's 5s, and deliberately so: this runs after the
     * response has been sent (or from cron) with nobody waiting, and the ingest
     * endpoint is serverless — a cold start measured over 30 seconds during the
     * reference implementation's end-to-end verification. Too tight a timeout
     * on a rarely-hit endpoint means a flush that only ever succeeds once
     * something else has warmed it. Still bounded, because a hung endpoint must
     * cost a skipped flush — the batch stays buffered and is retried verbatim —
     * never a hung worker.
     */
    private const TIMEOUT_SECONDS = 20;
    private const MAX_DURATION_SECONDS = 30;

    public function __construct(private readonly HttpClientInterface $client)
    {
    }

    public function post(string $url, string $token, string $json): IngestResult
    {
        $body = self::toWire($json);
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'GlobetrottersAiPresence/'.GlobetrottersAiPresenceBundle::VERSION,
        ];
        if ($body !== $json) {
            $headers['Content-Encoding'] = 'gzip';
        }

        try {
            $response = $this->client->request('POST', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
                // The issued endpoint is final. Following a redirect can turn
                // the POST into a GET whose unrelated 2xx would look accepted.
                'max_redirects' => 0,
                'headers' => $headers,
                'body' => $body,
            ]);

            // The endpoint answers 202 with an empty body on every outcome, so
            // the status is the only thing worth waiting for. getStatusCode()
            // blocks on the response headers; the body is then discarded.
            $status = $response->getStatusCode();
            $response->cancel();

            return IngestResult::http($status);
        } catch (TransportExceptionInterface $e) {
            return IngestResult::error($e->getMessage());
        }
    }

    public function wireSize(string $json): int
    {
        return \strlen(self::toWire($json));
    }

    public function wireCap(): int
    {
        return self::gzipAvailable() ? self::MAX_COMPRESSED_BODY_BYTES : self::MAX_BODY_BYTES;
    }

    /**
     * The request body for an envelope: gzipped when zlib is available.
     */
    private static function toWire(string $json): string
    {
        if (!self::gzipAvailable()) {
            return $json;
        }

        $compressed = gzencode($json);

        return \is_string($compressed) && '' !== $compressed ? $compressed : $json;
    }

    private static function gzipAvailable(): bool
    {
        return \function_exists('gzencode');
    }
}
