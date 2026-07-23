<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Scheduler;

use Globetrotters\AiPresenceBundle\Sync\ArtefactSync;

final class RefreshMessageHandler
{
    public function __construct(private readonly ArtefactSync $sync)
    {
    }

    public function __invoke(RefreshMessage $message): void
    {
        // Failures are recorded in state (last_error) and the previous bundle
        // keeps serving; no exception should kill the worker.
        $this->sync->run();
    }
}
