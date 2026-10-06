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
        // The schedule polls; refresh_interval decides, exactly as gt:refresh
        // does under an hourly cron. Forgetting first resets last_refresh, so a
        // repointed website_url is pulled on this poll rather than an interval
        // later.
        $this->sync->forgetForeignBundle();
        if (!$this->sync->isDue()) {
            return;
        }

        // Failures are recorded in state (last_error) and the previous bundle
        // keeps serving; no exception should kill the worker. A failed pull
        // leaves last_refresh alone, so the next poll retries it.
        $this->sync->run();
    }
}
