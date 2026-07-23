<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Client;

/**
 * Fetches a single artefact URL and normalises the outcome into a FetchResult
 * so the sync layer treats transport errors, 404s and 200s uniformly.
 */
interface FetcherInterface
{
    /**
     * Largest artefact body accepted, in bytes (1 MiB). Implementations read
     * one byte past this cap so an oversize body arrives detectably truncated.
     */
    public const MAX_BODY_BYTES = 1048576;

    public function fetch(string $url): FetchResult;
}
