<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

/**
 * What one ``GET <endpoint>/ranges`` came back with.
 *
 * Like {@see IngestResult} it never carries the token, so it can be logged or
 * shown as is.
 */
final class RangesFetch
{
    private function __construct(
        private readonly int $status,
        private readonly string $body,
        private readonly string $error,
    ) {
    }

    public static function http(int $status, string $body = ''): self
    {
        return new self($status, $body, '');
    }

    public static function error(string $message): self
    {
        return new self(0, '', $message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isNotModified(): bool
    {
        return 304 === $this->status;
    }

    public function isOk(): bool
    {
        return 200 === $this->status;
    }

    public function errorMessage(): string
    {
        if ('' !== $this->error) {
            return $this->error;
        }

        return $this->isOk() || $this->isNotModified() ? '' : 'HTTP '.$this->status;
    }
}
