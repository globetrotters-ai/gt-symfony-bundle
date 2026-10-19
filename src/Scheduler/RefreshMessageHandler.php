<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Scheduler;

use Globetrotters\AiPresenceBundle\Analytics\VendorRanges;
use Globetrotters\AiPresenceBundle\Sync\ArtefactSync;
use Globetrotters\AiPresenceBundle\Sync\RefreshLock;

final class RefreshMessageHandler
{
    public function __construct(
        private readonly ArtefactSync $sync,
        private readonly ?VendorRanges $ranges = null,
        private readonly ?RefreshLock $lock = null,
    ) {
    }

    public function __invoke(RefreshMessage $message): void
    {
        // The vendor range list rides the same hourly poll on its own daily
        // due-check, before and independently of the artefact sync: it needs
        // no website_url and no published bundle. Never throws.
        $this->ranges?->refreshIfDue();

        // Under the refresh lock, due-check included, so a cron'd gt:refresh
        // firing at the same moment skips instead of pulling a second time,
        // and whichever runs second finds the first one's last_refresh. Held
        // elsewhere: this poll does nothing and the next one asks again.
        $refresh = function (): bool {
            // The schedule polls; refresh_interval decides, exactly as
            // gt:refresh does under an hourly cron. Forgetting first resets
            // last_refresh, so a repointed website_url is pulled on this poll
            // rather than an interval later.
            $this->sync->forgetForeignBundle();
            if (!$this->sync->isDue()) {
                return true;
            }

            // Failures are recorded in state (last_error) and the previous
            // bundle keeps serving; no exception should kill the worker. A
            // failed pull leaves last_refresh alone, so the next poll retries.
            $this->sync->run();

            return true;
        };

        null === $this->lock ? $refresh() : $this->lock->withLock($refresh);
    }
}
