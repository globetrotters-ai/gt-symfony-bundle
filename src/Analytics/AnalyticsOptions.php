<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

/**
 * The reporting lane's configuration, as resolved from the bundle's config
 * tree.
 *
 * Studio issues the endpoint URL and the ingest token together on the apex
 * install screen — the token exactly once — so both are configuration rather
 * than a hardcoded host. Capture and flushing are gated on both being present:
 * an install that will never report should not be paying a buffered row per
 * served artefact request.
 *
 * The token is a credential. It is never logged, never rendered in full, never
 * put in an exception message, and never carried on an {@see IngestResult}.
 */
final class AnalyticsOptions
{
    public function __construct(
        private readonly bool $enabled,
        private readonly string $endpoint,
        private readonly string $ingestToken,
        private readonly bool $opportunisticFlush,
        private readonly bool $trustCloudflareHeader,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function endpoint(): string
    {
        return trim($this->endpoint);
    }

    public function ingestToken(): string
    {
        return trim($this->ingestToken);
    }

    /**
     * Whether reporting has everything it needs to run.
     */
    public function isConfigured(): bool
    {
        return $this->enabled && '' !== $this->endpoint() && '' !== $this->ingestToken();
    }

    public function opportunisticFlush(): bool
    {
        return $this->opportunisticFlush;
    }

    public function trustCloudflareHeader(): bool
    {
        return $this->trustCloudflareHeader;
    }

    /**
     * A non-reversible hint that the configured token is the one the operator
     * thinks it is, for the status command.
     *
     * Four characters of a 43-character URL-safe token is not a meaningful
     * disclosure, and it is what makes "I pasted the wrong one" diagnosable
     * without ever printing the credential.
     */
    public function tokenHint(): string
    {
        $token = $this->ingestToken();

        return '' === $token ? '' : '…'.substr($token, -4);
    }
}
