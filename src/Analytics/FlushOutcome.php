<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

/**
 * What one {@see Flusher::run()} did.
 *
 * Only {@see self::Rejected} is a failure. The two skips are the gate working
 * as intended — another lane flushed recently, or is flushing right now — and a
 * caller that reported them as errors would turn every healthy overlap between
 * cron, Scheduler and the terminate fallback into a false alarm.
 */
enum FlushOutcome
{
    /** At least one batch was accepted — a 2xx, which is hand-off, not validation. */
    case Accepted;

    /** A flush ran and nothing was accepted; the events stay buffered for an identical retry. */
    case Rejected;

    /** The shared interval has not elapsed since any lane last attempted a flush. */
    case NotDue;

    /** Another process holds the flush lock, so it is flushing right now. */
    case Locked;

    /** Reporting is not configured, or the buffer directory is unusable. */
    case Unavailable;
}
