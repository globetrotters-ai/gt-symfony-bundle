<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Command;

use Globetrotters\AiPresenceBundle\Settings\Options;
use Globetrotters\AiPresenceBundle\Sync\ArtefactSync;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pulls the artefact bundle from the configured Globetrotters subdomain.
 *
 * Without --force the command respects the configured refresh_interval as a
 * due-check, so it can safely be cron'd more frequently than the cadence
 * (e.g. hourly) and still refresh daily/weekly.
 */
#[AsCommand(name: 'gt:refresh', description: 'Pull the Globetrotters AI presence artefacts into the cache')]
final class RefreshCommand extends Command
{
    public function __construct(
        private readonly ArtefactSync $sync,
        private readonly Options $options,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Pull now, ignoring the refresh interval');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->options->isConnected()) {
            $io->warning('No website_url configured — nothing to refresh.');

            return Command::SUCCESS;
        }

        if (!$input->getOption('force') && !$this->isDue()) {
            $io->writeln('Refresh not due yet (interval: '.$this->options->refreshInterval().'). Use --force to pull now.');

            return Command::SUCCESS;
        }

        $result = $this->sync->run();

        if (!$result->isSuccess()) {
            $io->error('Refresh failed; the last known good bundle keeps serving. '.$result->errorMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            'Refreshed to version %s (%s).',
            $result->version(),
            $result->hasChanged() ? 'content changed' : 'content unchanged',
        ));

        return Command::SUCCESS;
    }

    private function isDue(): bool
    {
        $lastRefresh = (int) $this->options->state()['last_refresh'];
        if (0 === $lastRefresh) {
            return true;
        }

        return $this->clock->now()->getTimestamp() - $lastRefresh >= $this->options->refreshIntervalSeconds();
    }
}
