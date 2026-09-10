<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\AnalyticsState;
use Globetrotters\AiPresenceBundle\Analytics\EventBuffer;
use Globetrotters\AiPresenceBundle\Analytics\Flusher;
use Globetrotters\AiPresenceBundle\Analytics\FlushGate;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The no-worker flush lane.
 *
 * A Symfony bundle drops into applications that may have no supervisor, no
 * systemd unit and no ability to run ``messenger:consume`` — a shared host with
 * nothing but a document root. A design that only flushes from a worker would
 * silently never flush there, which is the same invisible-undercount failure
 * this feature exists to remove.
 *
 * kernel.terminate is the one place a bundle can do network I/O without adding
 * to response latency — but only on a runtime that has already delivered the
 * response by then. {@see ResponseFinalization} decides that: PHP-FPM,
 * FrankenPHP and LiteSpeed qualify; mod_php and the CLI server do not, and
 * there this lane stays off and cron or Scheduler carry the flush. Even where
 * the visitor has their response, the worker process is still occupied for the
 * length of the ingest call (up to its 20-second timeout) and serves no one
 * else meanwhile, which is why this lane sends one batch rather than five.
 *
 * Only a request this bundle served as an artefact can trigger it, never an
 * ordinary page: the application's own traffic must not pay for reporting.
 * The sitemap and the IndexNow key are marked with their own attributes and
 * are excluded the same way they are from capture.
 *
 * Rate-limited to at most one attempt every 15 minutes regardless of traffic,
 * through the same stamp file every other lane writes — so on an install that
 * *does* run cron or Scheduler, this lane finds the interval already satisfied
 * and stays dormant.
 */
final class OpportunisticFlushSubscriber implements EventSubscriberInterface
{
    /**
     * After {@see ArtefactCaptureSubscriber} (0), so an event captured on this
     * very request can go out in this very flush.
     */
    public const PRIORITY = -256;

    /**
     * One batch per request. The console and Scheduler lanes drain up to five;
     * a web worker holding a socket open is a different trade.
     */
    private const MAX_BATCHES = 1;

    public function __construct(
        private readonly Flusher $flusher,
        private readonly EventBuffer $buffer,
        private readonly AnalyticsOptions $options,
        private readonly FlushGate $gate,
        private readonly ResponseFinalization $runtime,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => ['onKernelTerminate', self::PRIORITY]];
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        try {
            // A request attribute read first, so an ordinary page costs one
            // array lookup and nothing else.
            $path = $event->getRequest()->attributes->get(Router::ATTRIBUTE_PATH);
            if (!\is_string($path) || '' === $path) {
                return;
            }
            if (!$this->options->opportunisticFlush() || !$this->options->isConfigured()) {
                return;
            }
            if (!$this->runtime->finishesBeforeTerminate()) {
                return;
            }
            // Two stat() calls before anything expensive: nothing buffered, or
            // not yet due, and this request is done. The flusher re-checks the
            // interval under its lock; this is only the cheap early exit.
            if ($this->buffer->sizeBytes() <= 0 || !$this->gate->isDue()) {
                return;
            }

            $this->flusher->run(AnalyticsState::LANE_TERMINATE, self::MAX_BATCHES);
        } catch (\Throwable) {
            // The response is already on the wire; there is nobody to tell and
            // nothing to gain from letting this escape into the app's error
            // handling.
        }
    }
}
