<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

/**
 * One served artefact request, as this producer observed it.
 *
 * Mirrors ``ServerLogEvent`` in the backend contract
 * (gt-backend ``docs/contracts/presence-server-log-producer.md``). String
 * fields are clipped at {@see MAX_FIELD_CHARS} locally: the backend clips too
 * and still counts the event, but clipping here is what keeps the buffer's
 * 512KB bound honest — one pathological User-Agent must not be able to eat the
 * whole allowance.
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

    public function __construct(
        private readonly string $id,
        private readonly string $ts,
        private readonly string $path,
        string $ua,
        string $ip,
        string $referer,
        private readonly int $status,
        private readonly int $bytes,
    ) {
        $this->ua = self::clip($ua);
        $this->ip = self::clip($ip);
        $this->referer = self::clip($referer);
    }

    /**
     * Rebuild an event from a decoded buffer line.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            self::str($row, 'id'),
            self::str($row, 'ts'),
            self::str($row, 'path'),
            self::str($row, 'ua'),
            self::str($row, 'ip'),
            self::str($row, 'referer'),
            (int) ($row['status'] ?? 0),
            (int) ($row['bytes'] ?? 0),
        );
    }

    /**
     * Wire representation, per the contract.
     *
     * Empty optional fields go out as ``null`` rather than ``""``: the contract
     * models them as nullable, and a null is smaller on the wire.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
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
        $json = json_encode($this->toPayload(), self::JSON_FLAGS);

        return \is_string($json) ? $json : '';
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
