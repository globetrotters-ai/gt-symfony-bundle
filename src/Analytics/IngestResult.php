<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

/**
 * Normalised outcome of one ingest POST.
 *
 * Carries a status and a message only — never the request body and never the
 * ingest token, so a result can be stored in state and printed by the status
 * command without leaking a credential.
 *
 * ``isAccepted()`` means *handed off*, not *correct*: the endpoint answers
 * ``202`` for a good batch and equally for a bad token, an unknown install or a
 * malformed body, deliberately revealing nothing about which tokens exist. This
 * bundle can therefore never claim reporting "works" — only that its flushes
 * are being accepted.
 */
final class IngestResult
{
    private function __construct(
        private readonly int $status,
        private readonly string $error,
    ) {
    }

    /**
     * A transport failure (DNS, timeout, TLS).
     */
    public static function error(string $message): self
    {
        return new self(0, $message);
    }

    public static function http(int $status): self
    {
        return new self($status, '');
    }

    public function isAccepted(): bool
    {
        // The ingest contract has exactly one hand-off response. Treating a
        // generic 2xx as acceptance can discard a batch when a misconfigured
        // endpoint answers with a login page or another unrelated success.
        return 202 === $this->status;
    }

    /**
     * Whether the producer is being rate limited and should back off, retrying
     * the same payload.
     */
    public function isRateLimited(): bool
    {
        return 429 === $this->status;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * A short, credential-free description of the failure ('' when accepted).
     */
    public function errorMessage(): string
    {
        if ($this->isAccepted()) {
            return '';
        }
        if ('' !== $this->error) {
            return $this->error;
        }

        return \sprintf('ingest endpoint returned HTTP %d', $this->status);
    }
}
