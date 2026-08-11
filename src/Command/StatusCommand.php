<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Command;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\AnalyticsState;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\EventBuffer;
use Globetrotters\AiPresenceBundle\Analytics\FlushGate;
use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Globetrotters\AiPresenceBundle\Sync\ArtefactSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reports connection, installed vs latest version, last refresh and last
 * error, plus the state of the agent-traffic reporting lane. Passive by
 * default; --remote also queries the upstream drift marker.
 *
 * The reporting section replaces what a plugin would put on a settings screen.
 * It matters more than a status readout usually does: the ingest endpoint
 * answers 202 to a bad token, an unknown install and a malformed body alike, so
 * an integrator who pasted the wrong token gets no error anywhere and this
 * command is the only place the silence is visible.
 */
#[AsCommand(name: 'gt:status', description: 'Show the Globetrotters AI presence sync and reporting status')]
final class StatusCommand extends Command
{
    public function __construct(
        private readonly Options $options,
        private readonly ArtefactCache $cache,
        private readonly ArtefactSync $sync,
        private readonly AnalyticsOptions $analytics,
        private readonly AnalyticsState $analyticsState,
        private readonly EventBuffer $buffer,
        private readonly FlushGate $gate,
        private readonly BufferDirectory $bufferDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('remote', null, InputOption::VALUE_NONE, 'Also query the upstream version marker for drift');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $state = $this->options->state();

        $latest = (string) $state['latest_version'];
        if ($input->getOption('remote') && $this->options->isConnected()) {
            $upstream = $this->sync->checkLatest();
            if ('' !== $upstream) {
                $latest = $upstream;
                $this->options->updateState(['latest_version' => $upstream]);
            }
        }

        $lastRefresh = (int) $state['last_refresh'];

        $io->section('Artefacts');
        $io->table([], [
            ['Connected', $this->options->isConnected() ? 'yes ('.$this->options->baseUrl().')' : 'no'],
            ['Bundle cached', $this->cache->hasAny() ? 'yes' : 'no'],
            ['Installed version', '' !== (string) $state['installed_version'] ? (string) $state['installed_version'] : '—'],
            ['Latest version', '' !== $latest ? $latest : '—'],
            ['Content hash', '' !== (string) $state['content_hash'] ? (string) $state['content_hash'] : '—'],
            ['Last refresh', $lastRefresh > 0 ? gmdate('Y-m-d H:i:s', $lastRefresh).' UTC' : 'never'],
            ['Last error', '' !== (string) $state['last_error'] ? (string) $state['last_error'] : '—'],
        ]);

        $installed = (string) $state['installed_version'];
        if ('' !== $latest && '' !== $installed && $latest !== $installed) {
            $io->warning(\sprintf('A newer version (%s) is available — you are serving %s. Run gt:refresh --force.', $latest, $installed));
        }

        $this->reportAgentTraffic($io);

        return Command::SUCCESS;
    }

    private function reportAgentTraffic(SymfonyStyle $io): void
    {
        $io->section('Agent-traffic reporting');

        if (!$this->analytics->isEnabled()) {
            $io->writeln('Disabled (globetrotters_ai_presence.reporting.enabled: false). Artefact hits are not reported.');

            return;
        }

        if (!$this->analytics->isConfigured()) {
            $io->table([], [
                ['Endpoint', '' !== $this->analytics->endpoint() ? $this->analytics->endpoint() : 'not set'],
                ['Ingest token', '' !== $this->analytics->tokenHint() ? 'set ('.$this->analytics->tokenHint().')' : 'not set'],
            ]);
            $io->warning('Not reporting: the endpoint and token are issued together on the Studio apex install screen, and both are required. Nothing is being captured, so agent traffic to this apex is invisible.');

            return;
        }

        $state = $this->analyticsState->state();
        $lastOk = (int) $state['last_flush_ok'];
        $lastAttempt = (int) $state['last_flush_attempt'];
        $usable = $this->buffer->isUsable();

        $io->table([], [
            ['Endpoint', $this->analytics->endpoint()],
            ['Ingest token', 'set ('.$this->analytics->tokenHint().')'],
            ['Buffer directory', $this->bufferDir->dir().($usable ? '' : ' — NOT WRITABLE')],
            ['Buffered events', $usable ? \sprintf('%d (%d bytes)', $this->buffer->count(), $this->buffer->sizeBytes()) : '—'],
            ['Dropped (pending / total)', $usable ? \sprintf('%d / %d', $this->buffer->droppedPending(), $this->buffer->droppedTotal()) : '—'],
            ['Last flush attempt', $lastAttempt > 0 ? gmdate('Y-m-d H:i:s', $lastAttempt).' UTC' : 'never'],
            ['Last accepted flush', $lastOk > 0 ? gmdate('Y-m-d H:i:s', $lastOk).' UTC' : 'never'],
            ['Accepted batches / events', \sprintf('%d / %d', (int) $state['flush_count'], (int) $state['events_sent'])],
            ['Scheduling lane in use', $this->lane($state)],
            ['Client IP resolution', $this->ipTrust($state)],
            ['Last flush error', '' !== (string) $state['last_flush_error'] ? (string) $state['last_flush_error'] : '—'],
        ]);

        if (!$usable) {
            $io->error(\sprintf('The buffer directory is not writable, so nothing is being captured: %s', $this->bufferDir->dir()));

            return;
        }

        if (0 === $lastOk) {
            $io->warning('Configured, but no flush has ever been accepted. Run gt:presence:flush --force to try one now; if that stays silent, check that a cron entry, a Messenger worker, or opportunistic_flush is actually running.');

            return;
        }

        if (false === $state['ip_trustworthy']) {
            $io->warning('This application is behind a proxy that is not in framework.trusted_proxies, so every event reports the proxy\'s IP. The backend cannot forward-confirm those against published vendor ranges, and every row will be recorded unverified. Configure trusted_proxies (and reporting.trust_cloudflare_header behind Cloudflare).');
        }

        $io->writeln('Reporting normally. Note that the endpoint answers 202 to a bad token as well as a good one, so an accepted flush proves hand-off, not that this install is recognised — confirm the numbers in Studio.');
    }

    /**
     * @param array<string, mixed> $state
     */
    private function lane(array $state): string
    {
        $lane = (string) $state['last_flush_lane'];
        if ('' === $lane) {
            return 'none has run yet';
        }

        $due = $this->gate->isDue() ? 'due now' : 'next in '.max(0, FlushGate::INTERVAL_SECONDS - $this->sinceLastAttempt()).'s';

        return \sprintf('%s (%s)', $lane, $due);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function ipTrust(array $state): string
    {
        $proxies = Request::getTrustedProxies();

        if (false === $state['ip_observed']) {
            return [] === $proxies
                ? 'no captured request yet; no trusted proxies configured'
                : \sprintf('no captured request yet; %d trusted proxy entr%s configured', \count($proxies), 1 === \count($proxies) ? 'y' : 'ies');
        }

        return false === $state['ip_trustworthy']
            ? 'UNTRUSTWORTHY — forwarded requests arrive from an untrusted proxy'
            : 'looks trustworthy';
    }

    private function sinceLastAttempt(): int
    {
        $last = $this->gate->lastAttemptAt();

        return null === $last ? 0 : max(0, time() - $last);
    }
}
