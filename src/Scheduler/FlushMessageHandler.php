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
        // Failures are recorded in state and the batch stays buffered for the
        // next run; no exception should kill the worker.
        $this->flusher->run(AnalyticsState::LANE_SCHEDULER);
    }
}
