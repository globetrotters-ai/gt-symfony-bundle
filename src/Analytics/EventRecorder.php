<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds and buffers one event per served artefact request.
 *
 * Two rules govern everything here:
 *
 * 1. **Nothing may affect the served response.** ``record()`` swallows every
 *    Throwable; an unwritable buffer, a full disk or a fatal in the store
 *    degrades reporting and nothing else. It also runs on ``kernel.terminate``,
 *    after the response has been sent, so even the happy path costs the visitor
 *    nothing.
 * 2. **The timestamp is UTC with an explicit offset, always.** See
 *    {@see timestamp()} — this is the single most likely thing to get silently
 *    wrong.
 */
final class EventRecorder
{
    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly AnalyticsOptions $options,
        private readonly ClientIpResolver $ipResolver,
        private readonly AnalyticsState $state,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Capture one served artefact request.
     *
     * @param string $path   canonical artefact path, with a leading slash
     * @param int    $status HTTP status served
     * @param int    $bytes  served body size in bytes
     */
    public function record(Request $request, string $path, int $status, int $bytes): void
    {
        try {
            if (!$this->isCapturing()) {
                return;
            }

            $this->buffer->append($this->build($request, $path, $status, $bytes));
            $this->state->observeIpTrust($this->ipResolver->looksTrustworthy($request));
        } catch (\Throwable) {
            // Deliberately swallowed: capture must not affect a served response
            // on any code path, including failure paths.
        }
    }

    /**
     * Whether this install captures at all.
     */
    public function isCapturing(): bool
    {
        return $this->options->isConfigured() && $this->buffer->isUsable();
    }

    public function build(Request $request, string $path, int $status, int $bytes): Event
    {
        return new Event(
            Uuid::v4(),
            $this->timestamp(),
            $path,
            (string) $request->headers->get('User-Agent', ''),
            $this->ipResolver->resolve($request),
            (string) $request->headers->get('Referer', ''),
            $status,
            $bytes,
        );
    }

    /**
     * The event timestamp: UTC, ISO 8601, explicit offset.
     *
     * ``new \DateTime()`` would use the application's ``date.timezone``, and
     * ``format('Y-m-d\TH:i:s')`` emits no offset at all. The backend reads a
     * bare timestamp as UTC, so an app configured ``Europe/Paris`` would report
     * every hit two hours off — and the backend re-stamps anything more than 90
     * minutes stale to arrival time rather than dropping it, so the mistake
     * degrades quietly rather than loudly. Converting to UTC and formatting
     * with a literal ``Z`` makes it unambiguous on the wire.
     */
    private function timestamp(): string
    {
        return $this->clock->now()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
