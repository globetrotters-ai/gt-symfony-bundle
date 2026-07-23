<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Client;

/**
 * Immutable outcome of a single fetch: either an HTTP response
 * (any status) or a transport-level error.
 */
final class FetchResult
{
    private function __construct(
        private readonly int $status,
        private readonly string $body,
        private readonly string $error,
    ) {
    }

    public static function error(string $message): self
    {
        return new self(0, '', $message);
    }

    public static function http(int $status, string $body): self
    {
        return new self($status, $body, '');
    }

    /**
     * An empty 200 body is still OK — an artefact may legitimately be empty.
     */
    public function isOk(): bool
    {
        return 200 === $this->status;
    }

    public function isTransportError(): bool
    {
        return '' !== $this->error;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function errorMessage(): string
    {
        return $this->error;
    }
}
