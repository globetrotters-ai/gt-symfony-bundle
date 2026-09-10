<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Command;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\AnalyticsState;
use Globetrotters\AiPresenceBundle\Analytics\EventBuffer;
use Globetrotters\AiPresenceBundle\Analytics\Flusher;
use Globetrotters\AiPresenceBundle\Analytics\FlushGate;
use Globetrotters\AiPresenceBundle\Analytics\FlushOutcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ships buffered artefact hits to the Globetrotters ingest endpoint.
 *
 * The primary flush lane, for system cron or a systemd timer. Like gt:refresh
 * it enforces its own cadence — the contract's 15 minutes — so it is safe to
 * schedule more often than that; see the README for the crontab line.
 */
#[AsCommand(name: 'gt:presence:flush', description: 'Flush buffered agent-traffic events to Globetrotters')]
final class PresenceFlushCommand extends Command
{
    public function __construct(
        private readonly Flusher $flusher,
        private readonly EventBuffer $buffer,
        private readonly AnalyticsOptions $options,
        private readonly AnalyticsState $state,
        private readonly FlushGate $gate,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Flush now, ignoring the 15-minute interval');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->options->isEnabled()) {
            $io->writeln('Reporting is disabled (globetrotters_ai_presence.reporting.enabled: false).');

            return Command::SUCCESS;
        }

        if (!$this->options->isConfigured()) {
            $io->warning('No ingest endpoint and token configured — nothing to flush. Both are issued together on the Studio apex install screen.');

            return Command::SUCCESS;
        }

        if (!$this->buffer->isUsable()) {
            $io->error(\sprintf('Buffer directory is not writable, so nothing was ever captured: %s', $this->bufferHint()));

            return Command::FAILURE;
        }

        // An empty buffer still flushes: an empty envelope is how the backend's
        // "no batch received in 24h" install-health watermark gets stamped.
        $buffered = $this->buffer->count();
        $outcome = $this->flusher->run(AnalyticsState::LANE_COMMAND, force: (bool) $input->getOption('force'));

        // The interval is decided by the flusher under its lock rather than
        // here, so this command cannot race the Scheduler or terminate lanes
        // into a second POST. Both skips are the gate doing its job.
        if (FlushOutcome::NotDue === $outcome) {
            $io->writeln(\sprintf(
                'Not due yet — last attempt %ds ago, interval %ds. Use --force to flush now.',
                $this->sinceLastAttempt(),
                FlushGate::INTERVAL_SECONDS,
            ));

            return Command::SUCCESS;
        }
        if (FlushOutcome::Locked === $outcome) {
            // --force skips the interval, never the lock.
            $io->writeln('Another flush is already running; skipped.');

            return Command::SUCCESS;
        }
        if (FlushOutcome::Unavailable === $outcome) {
            $io->error('Reporting became unavailable before the flush could run.');

            return Command::FAILURE;
        }

        if (0 === $buffered) {
            if (FlushOutcome::Accepted !== $outcome) {
                $error = (string) $this->state->state()['last_flush_error'];
                $io->warning('Health heartbeat not accepted; it will be retried.'.('' !== $error ? ' '.$error : ''));

                return Command::FAILURE;
            }
            $io->writeln('Nothing buffered.');

            return Command::SUCCESS;
        }

        $remaining = $this->buffer->count();

        if (FlushOutcome::Accepted !== $outcome) {
            $error = (string) $this->state->state()['last_flush_error'];
            $io->warning(\sprintf(
                'Flush not accepted; %d event(s) stay buffered and will be retried with the same UUIDs.%s',
                $remaining,
                '' !== $error ? ' '.$error : '',
            ));

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            'Accepted %d event(s); %d still buffered. Note that a 202 confirms hand-off, not that the token is valid.',
            $buffered - $remaining,
            $remaining,
        ));

        return Command::SUCCESS;
    }

    private function sinceLastAttempt(): int
    {
        $last = $this->gate->lastAttemptAt();

        return null === $last ? 0 : max(0, time() - $last);
    }

    private function bufferHint(): string
    {
        return 'set globetrotters_ai_presence.reporting.buffer_dir to a directory writable by both the web user and the flush user';
    }
}
