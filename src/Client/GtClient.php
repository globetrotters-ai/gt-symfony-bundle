<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Client;

use Globetrotters\AiPresenceBundle\GlobetrottersAiPresenceBundle;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches artefacts from the configured Globetrotters subdomain.
 *
 * Hardening ported from the WordPress client: short timeout, identifying
 * user-agent, and a body-size cap that reads one byte past the maximum so an
 * oversize body arrives detectably truncated. The SSRF guard lives in the
 * service wiring: the injected client is wrapped in NoPrivateNetworkHttpClient
 * because the configured website_url is untrusted input.
 */
final class GtClient implements FetcherInterface
{
    private const TIMEOUT_SECONDS = 5;
    private const MAX_DURATION_SECONDS = 30;

    public function __construct(private readonly HttpClientInterface $client)
    {
    }

    public function fetch(string $url): FetchResult
    {
        try {
            $response = $this->client->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
                'buffer' => false,
                'headers' => [
                    'Accept' => '*/*',
                    'User-Agent' => 'GlobetrottersAiPresence/'.GlobetrottersAiPresenceBundle::VERSION,
                ],
            ]);

            $status = $response->getStatusCode();

            $body = '';
            foreach ($this->client->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    $response->cancel();

                    return FetchResult::error('Timeout while reading response body.');
                }
                $body .= $chunk->getContent();
                if (\strlen($body) > FetcherInterface::MAX_BODY_BYTES) {
                    $response->cancel();

                    return FetchResult::http($status, substr($body, 0, FetcherInterface::MAX_BODY_BYTES + 1));
                }
            }

            return FetchResult::http($status, $body);
        } catch (TransportExceptionInterface $e) {
            return FetchResult::error($e->getMessage());
        }
    }
}
