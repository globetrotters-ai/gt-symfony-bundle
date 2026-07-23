<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Support;

use Globetrotters\AiPresenceBundle\Client\FetcherInterface;
use Globetrotters\AiPresenceBundle\Client\FetchResult;

/**
 * In-memory fetcher keyed by URL suffix, ported from the WP plugin's test
 * double. Records every requested URL for assertions.
 */
final class FakeFetcher implements FetcherInterface
{
    /** @var array<string, FetchResult> */
    private array $responses = [];

    private FetchResult $fallback;

    /** @var list<string> */
    public array $requested = [];

    public function __construct()
    {
        $this->fallback = FetchResult::http(404, '');
    }

    public function on(string $suffix, FetchResult $result): self
    {
        $this->responses[$suffix] = $result;

        return $this;
    }

    public function fallback(FetchResult $result): self
    {
        $this->fallback = $result;

        return $this;
    }

    public function fetch(string $url): FetchResult
    {
        $this->requested[] = $url;

        foreach ($this->responses as $suffix => $result) {
            if (str_ends_with($url, $suffix)) {
                return $result;
            }
        }

        return $this->fallback;
    }
}
