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

    /**
     * The configured ingest endpoint, or '' when it is not an https URL.
     *
     * The endpoint receives the bearer token and every captured client IP, so
     * a cleartext one reads as unconfigured and reporting stays off. The config
     * tree refuses a literal one at container build, but the documented setup
     * binds the value to an env var that is only resolved at runtime, so this
     * is the check that holds for it.
     */
    public function endpoint(): string
    {
        $endpoint = trim($this->endpoint);

        return self::isHttpsUrl($endpoint) ? $endpoint : '';
    }

    /**
     * Whether an endpoint is set but refused for not being https — which the
     * status command would otherwise report as "not set".
     */
    public function hasRefusedEndpoint(): bool
    {
        $endpoint = trim($this->endpoint);

        return '' !== $endpoint && !self::isHttpsUrl($endpoint);
    }

    /**
     * Whether a URL is an absolute https URL with a host, the same rule as
     * gt-wordpress-plugin's ``Options::is_https_url()``.
     */
    public static function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return \is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && 'https' === strtolower($parts['scheme'])
            && '' !== $parts['host'];
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
