<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

/**
 * Minimal RFC 4122 version-4 UUID generator.
 *
 * The ingest contract needs one UUID per event — it is the backend's dedupe
 * key, and the property that makes a retry safe. symfony/uid would do the same
 * job, but the bundle's dependency list is deliberately small and this is
 * fifteen lines against a hard requirement, so it is not worth a new require.
 */
final class Uuid
{
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        // Version 4 (random) in the high nibble of byte 6, RFC 4122 variant in
        // the two high bits of byte 8.
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'
            .substr($hex, 8, 4).'-'
            .substr($hex, 12, 4).'-'
            .substr($hex, 16, 4).'-'
            .substr($hex, 20, 12);
    }
}
