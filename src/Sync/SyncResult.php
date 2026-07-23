<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Sync;

/**
 * Immutable outcome of one refresh run.
 */
final class SyncResult
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        private readonly bool $success,
        private readonly bool $changed,
        private readonly string $version,
        private readonly array $errors,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function hasChanged(): bool
    {
        return $this->changed;
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function errorMessage(): string
    {
        return implode('; ', $this->errors);
    }
}
