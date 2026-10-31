<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

use Symfony\Component\HttpFoundation\Request;

/**
 * Web Bot Auth (RFC 9421 HTTP Message Signatures) headers, forwarded as
 * received (producer contract v7).
 *
 * Some agents drive an ordinary browser and send a stock browser User-Agent
 * (Meta Muse), but sign their requests. This producer verifies nothing: the
 * backend verifies at ingest, against the keys it ships. The job here is to
 * not drop a signed request at the site-capture gate, and to forward the three
 * headers byte for byte — the signature covers them — plus the authority the
 * request was served on. No IP. Kept in step with the WordPress plugin.
 */
final class Signature
{
    /**
     * Longest member kept. Longer than {@see Event::MAX_FIELD_CHARS}: a
     * ``Signature-Input`` with a ``keyid`` is already a few hundred bytes.
     */
    public const MAX_CHARS = 1024;

    /**
     * Whether a request carries a non-empty ``Signature-Agent`` header. A
     * string check, cheap enough for the site-capture gate.
     */
    public static function isSigned(Request $request): bool
    {
        return '' !== trim((string) $request->headers->get('Signature-Agent', ''));
    }

    /**
     * The ``signature`` object of an event, or null unless all three headers
     * are present and non-empty.
     *
     * @return array{agent: string, input: string, value: string, authority: string}|null
     */
    public static function fromRequest(Request $request): ?array
    {
        $agent = trim((string) $request->headers->get('Signature-Agent', ''));
        $input = trim((string) $request->headers->get('Signature-Input', ''));
        $value = trim((string) $request->headers->get('Signature', ''));
        if ('' === $agent || '' === $input || '' === $value) {
            return null;
        }

        return [
            'agent' => self::clip($agent),
            'input' => self::clip($input),
            'value' => self::clip($value),
            'authority' => self::clip(self::authority($request)),
        ];
    }

    /**
     * The ``signature`` object read back from a buffered line, or null when
     * it is absent, not the expected shape, or has a blank ``agent``,
     * ``input`` or ``value``.
     *
     * @return array{agent: string, input: string, value: string, authority: string}|null
     */
    public static function fromBuffered(mixed $row): ?array
    {
        if (!\is_array($row)) {
            return null;
        }
        $agent = $row['agent'] ?? null;
        $input = $row['input'] ?? null;
        $value = $row['value'] ?? null;
        $authority = $row['authority'] ?? null;
        if (!\is_string($agent) || !\is_string($input) || !\is_string($value) || !\is_string($authority)) {
            return null;
        }
        // The rule fromRequest() applies: a blank header is no signature.
        $agent = trim($agent);
        $input = trim($input);
        $value = trim($value);
        if ('' === $agent || '' === $input || '' === $value) {
            return null;
        }

        return [
            'agent' => self::clip($agent),
            'input' => self::clip($input),
            'value' => self::clip($value),
            'authority' => self::clip($authority),
        ];
    }

    /**
     * The RFC 9421 ``@authority`` the request was served on: the host, with
     * the port when it is not the scheme's default. Trusted-proxy rules
     * apply, as they do to the client IP.
     */
    private static function authority(Request $request): string
    {
        try {
            return $request->getHttpHost();
        } catch (\Throwable) {
            // An untrusted Host header: forward it as received, the backend
            // decides.
            return strtolower(trim((string) $request->headers->get('Host', '')));
        }
    }

    private static function clip(string $value): string
    {
        return \strlen($value) > self::MAX_CHARS ? substr($value, 0, self::MAX_CHARS) : $value;
    }
}
