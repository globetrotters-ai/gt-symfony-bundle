<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

/**
 * One served artefact request, as this producer observed it.
 *
 * Mirrors ``ServerLogEvent`` in the Globetrotters server-log producer
 * contract. String
 * fields are clipped at {@see MAX_FIELD_CHARS} locally: the backend clips too
 * and still counts the event, but clipping here is what keeps the buffer's
 * 512KB bound honest — one pathological User-Agent must not be able to eat the
 * whole allowance. The ``signature`` members (contract v7) are the exception,
 * clipped at {@see Signature::MAX_CHARS}: a signed line can reach a few KB.
 *
 * The buffered line and the wire payload are the same shape on purpose, so the
 * buffer's byte size *is* its wire size and no per-event size estimate has to
 * be maintained.
 */
final class Event
{
    /**
     * Longest string field the backend keeps before clipping.
     */
    public const MAX_FIELD_CHARS = 512;

    /**
     * The ``kind`` marker a site event carries in the buffer. Never sent: the
     * envelope separates the two kinds into ``events`` and ``siteEvents``.
     */
    public const KIND_SITE = 'site';

    /**
     * Encoding flags shared by the buffered line and the flush envelope.
     *
     * A User-Agent is recorded verbatim — it is evidence about what the agent
     * actually sent — and a hostile or broken one can carry bytes that are not
     * valid UTF-8, which json_encode refuses outright. Substituting rather than
     * failing keeps one malformed header from costing us the event (and, at
     * envelope level, the whole batch). PRESERVE_ZERO_FRACTION keeps
     * ``sampleRate`` on the wire as ``1.0`` rather than ``1``, matching the
     * float the contract types it as.
     */
    public const JSON_FLAGS = \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_UNESCAPED_SLASHES;

    private readonly string $ua;
    private readonly string $ip;
    private readonly string $referer;

    /**
     * Site events (design B) share the buffer with artefact events so both
     * live under one set of caps, but are a distinct line kind: no IP, no
     * referer, a nullable byte count and the client-computed verification.
     */
    private bool $site = false;
    private ?int $siteBytes = null;
    private ?bool $verified = null;
    private ?string $vendor = null;

    /**
     * The Web Bot Auth headers as received (contract v7), on either kind;
     * null when the request was not signed. Already validated and clipped
     * by {@see Signature} (from the request, or from a buffered line).
     *
     * @var array{agent: string, input: string, value: string, authority: string}|null
     */
    private readonly ?array $signature;

    /**
     * @param array{agent: string, input: string, value: string, authority: string}|null $signature
     */
    public function __construct(
        private readonly string $id,
        private readonly string $ts,
        private readonly string $path,
        string $ua,
        string $ip,
        string $referer,
        private readonly int $status,
        private readonly int $bytes,
        ?array $signature = null,
    ) {
        $this->signature = $signature;
        $this->ua = self::clip($ua);
        $this->ip = self::clip($ip);
        $this->referer = self::clip($referer);
    }

    /**
     * One site page fetched by a probable bot. Carries no IP by construction:
     * there is no parameter to pass one through.
     *
     * @param array{agent: string, input: string, value: string, authority: string}|null $signature
     */
    public static function site(string $id, string $ts, string $path, string $ua, int $status, ?int $bytes, ?bool $verified, ?string $vendor, ?array $signature = null): self
    {
        $event = new self($id, $ts, $path, $ua, '', '', $status, $bytes ?? 0, $signature);
        $event->site = true;
        $event->siteBytes = $bytes;
        $event->verified = $verified;
        $event->vendor = true === $verified && null !== $vendor && '' !== $vendor ? self::clip($vendor) : null;

        return $event;
    }

    /**
     * Rebuild an event from a decoded buffer line.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        if (self::KIND_SITE === ($row['kind'] ?? null)) {
            $bytes = $row['bytes'] ?? null;
            $verified = $row['verified'] ?? null;
            $vendor = $row['vendor'] ?? null;

            return self::site(
                self::str($row, 'id'),
                self::str($row, 'ts'),
                self::str($row, 'path'),
                self::str($row, 'ua'),
                (int) ($row['status'] ?? 0),
                \is_int($bytes) ? $bytes : null,
                \is_bool($verified) ? $verified : null,
                \is_string($vendor) ? $vendor : null,
                Signature::fromBuffered($row['signature'] ?? null),
            );
        }

        return new self(
            self::str($row, 'id'),
            self::str($row, 'ts'),
            self::str($row, 'path'),
            self::str($row, 'ua'),
            self::str($row, 'ip'),
            self::str($row, 'referer'),
            (int) ($row['status'] ?? 0),
            (int) ($row['bytes'] ?? 0),
            Signature::fromBuffered($row['signature'] ?? null),
        );
    }

    /**
     * Wire representation, per the contract.
     *
     * Empty optional fields go out as ``null`` rather than ``""``: the contract
     * models them as nullable, and a null is smaller on the wire. ``signature``
     * is left out entirely on an unsigned request, as the contract asks.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = $this->basePayload();
        if (null !== $this->signature) {
            $payload['signature'] = $this->signature;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(): array
    {
        if ($this->site) {
            return [
                'id' => $this->id,
                'ts' => $this->ts,
                'path' => $this->path,
                'ua' => '' === $this->ua ? null : $this->ua,
                'status' => $this->status,
                'bytes' => $this->siteBytes,
                'verified' => $this->verified,
                'vendor' => $this->vendor,
            ];
        }

        return [
            'id' => $this->id,
            'ts' => $this->ts,
            'path' => $this->path,
            'ua' => '' === $this->ua ? null : $this->ua,
            'ip' => '' === $this->ip ? null : $this->ip,
            'referer' => '' === $this->referer ? null : $this->referer,
            'status' => $this->status,
            'bytes' => $this->bytes,
        ];
    }

    /**
     * The buffered NDJSON line, without its newline. '' when the event cannot
     * be encoded at all, which the store treats as "do not buffer".
     */
    public function toLine(): string
    {
        $row = $this->toPayload();
        if ($this->site) {
            $row['kind'] = self::KIND_SITE;
        }
        $json = json_encode($row, self::JSON_FLAGS);

        return \is_string($json) ? $json : '';
    }

    public function isSite(): bool
    {
        return $this->site;
    }

    public function verified(): ?bool
    {
        return $this->verified;
    }

    public function vendor(): ?string
    {
        return $this->vendor;
    }

    /**
     * @return array{agent: string, input: string, value: string, authority: string}|null
     */
    public function signature(): ?array
    {
        return $this->signature;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function ts(): string
    {
        return $this->ts;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function ua(): string
    {
        return $this->ua;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function referer(): string
    {
        return $this->referer;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function str(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }

    private static function clip(string $value): string
    {
        return \strlen($value) > self::MAX_FIELD_CHARS
            ? substr($value, 0, self::MAX_FIELD_CHARS)
            : $value;
    }
}
