<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Scheduler;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsState;
use Globetrotters\AiPresenceBundle\Analytics\Flusher;

final class FlushMessageHandler
{
    public function __construct(private readonly Flusher $flusher)
    {
    }

    public function __invoke(FlushMessage $message): void
    {
        // The schedule polls; the flusher decides. It enforces the shared
        // interval under its lock, so a message arriving before the interval
        // has elapsed — another lane flushed recently, or Symfony 6.4 is
        // replaying runs a stopped worker missed — sends nothing. Failures are
        // recorded in state and the batch stays buffered for the next run; no
        // exception should kill the worker.
        $this->flusher->run(AnalyticsState::LANE_SCHEDULER);
    }
}
