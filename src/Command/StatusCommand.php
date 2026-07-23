<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Command;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Globetrotters\AiPresenceBundle\Sync\ArtefactSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports connection, installed vs latest version, last refresh and last
 * error. Passive by default; --remote also queries the upstream drift marker.
 */
#[AsCommand(name: 'gt:status', description: 'Show the Globetrotters AI presence sync status')]
final class StatusCommand extends Command
{
    public function __construct(
        private readonly Options $options,
        private readonly ArtefactCache $cache,
        private readonly ArtefactSync $sync,
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

        return Command::SUCCESS;
    }
}
