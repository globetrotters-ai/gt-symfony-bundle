<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

/**
 * Shape rules for the IndexNow key this apex serves at ``/<key>.txt``.
 *
 * IndexNow verifies control of a host by reading that file, and per
 * `indexnow.org/documentation <https://www.indexnow.org/documentation>`_ a key
 * file scopes submissions to **its own directory** — a key under
 * ``/.well-known/`` could cover ``mcp.json`` and ``agent-card.json`` but not
 * ``llms.txt`` / ``ai.json`` / ``schema.json``. So the key sits at the root, and
 * the filename is ``<key>.txt`` regardless of location (per the IndexNow FAQ).
 *
 * The key reaches this bundle over the network, on the version marker pulled
 * from the configured website URL — untrusted input like every other byte from
 * that origin. A value outside IndexNow's own grammar cannot verify a host
 * anyway, so it reads as *unconfigured* rather than as something to serve. That
 * is the same posture as the backend's ``_INDEXNOW_KEY_RE``
 * (``presence/apex/services/deploy_config_renderer.py``), which is what decides
 * whether the field is put on the marker at all; matching it here means the two
 * ends agree on what counts as a key.
 */
final class IndexNowKey
{
    /**
     * IndexNow's own key grammar: 8-128 characters of ``[A-Za-z0-9-]``.
     *
     * ``\z`` rather than ``$``, deliberately and for the same reason the backend
     * uses ``fullmatch`` on an unanchored pattern: ``$`` also matches *before* a
     * trailing newline, so ``$`` here would accept ``"<key>\n"`` — a stray
     * newline in a tfvars value is exactly the misconfiguration worth catching,
     * and it would land inside a served response body.
     */
    private const PATTERN = '/\A[A-Za-z0-9-]{8,128}\z/';

    private const SUFFIX = '.txt';

    /**
     * The key as it may be used, or an empty string when it may not be.
     */
    public static function sanitize(mixed $raw): string
    {
        return \is_string($raw) && 1 === preg_match(self::PATTERN, $raw) ? $raw : '';
    }

    /**
     * The apex-relative path a key is served at, or '' when it has none.
     */
    public static function pathFor(string $key): string
    {
        $key = self::sanitize($key);

        return '' === $key ? '' : $key.self::SUFFIX;
    }

    /**
     * The key a requested path would be the file for, or '' when it cannot be
     * one — a cheap structural test that runs before any stored value is read.
     *
     * Returns '' for every path in {@see ContentTypes}: none of them is a
     * root-level ``<key>.txt`` for a key of the required length, so a request
     * for a served artefact can never be read as a key lookup.
     */
    public static function candidateFromPath(string $path): string
    {
        $stemLength = \strlen($path) - \strlen(self::SUFFIX);
        if ($stemLength < 1 || self::SUFFIX !== substr($path, $stemLength)) {
            return '';
        }

        return self::sanitize(substr($path, 0, $stemLength));
    }
}
