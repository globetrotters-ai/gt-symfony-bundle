<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Fixtures;

use Globetrotters\AiPresenceBundle\Analytics\IngestTransportInterface;
use Globetrotters\AiPresenceBundle\Client\FetcherInterface;
use Globetrotters\AiPresenceBundle\GlobetrottersAiPresenceBundle;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeFetcher;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeIngestTransport;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const WEBSITE_URL = 'https://demo.globetrotters.ai';

    public const INGEST_ENDPOINT = 'https://api.globetrotters.ai/presence/analytics/server-log';
    public const INGEST_TOKEN = 'test-ingest-token-abcd';

    public function __construct(
        string $environment = 'test',
        bool $debug = false,
        private readonly bool $withRobotsRoute = false,
        private readonly bool $withReporting = false,
        private readonly bool $withOpportunisticFlush = false,
    ) {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new GlobetrottersAiPresenceBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return __DIR__.'/var/cache/'.$this->environment.$this->variant();
    }

    /**
     * Where the reporting lane buffers events, per kernel variant so one test's
     * buffer can never be read by another's.
     */
    public function bufferDir(): string
    {
        return __DIR__.'/var/presence'.$this->variant();
    }

    public function getLogDir(): string
    {
        return __DIR__.'/var/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test-secret',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ]);

        $container->extension('globetrotters_ai_presence', [
            'website_url' => self::WEBSITE_URL,
            'reporting' => [
                // Capture is gated on both credentials, so the default kernel
                // exercises the "configured but not reporting" path that most
                // installs are in.
                'endpoint' => $this->withReporting ? self::INGEST_ENDPOINT : '',
                'ingest_token' => $this->withReporting ? self::INGEST_TOKEN : '',
                'buffer_dir' => $this->bufferDir(),
                // Off unless a test is exercising it: the fallback lane fires
                // on every terminate, so leaving it on would drain the buffer
                // out from under a test that is asserting on capture.
                'opportunistic_flush' => $this->withOpportunisticFlush,
            ],
        ]);

        $services = $container->services();
        $services->set(FakeFetcher::class)->public();

        if ($this->withReporting) {
            $services->set(FakeIngestTransport::class)->public();
            // App-level alias wins over the bundle's IngestClient alias, so the
            // flush lane runs network-free.
            $services->alias(IngestTransportInterface::class, FakeIngestTransport::class)->public();
        }
        // App-level alias wins over the bundle's GtClient alias, so the sync
        // layer runs network-free.
        $services->alias(FetcherInterface::class, FakeFetcher::class)->public();
        $services->set(AntagonistController::class)
            ->public()
            ->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        if ($this->withRobotsRoute) {
            $routes->add('app_robots', '/robots.txt')
                ->controller([AntagonistController::class, 'robots']);
        }

        $routes->add('catchall', '/{path}')
            ->requirements(['path' => '.*'])
            ->defaults(['path' => ''])
            ->controller([AntagonistController::class, 'handle']);
    }

    private function variant(): string
    {
        return ($this->withRobotsRoute ? '_robots' : '')
            .($this->withReporting ? '_reporting' : '')
            .($this->withOpportunisticFlush ? '_terminate' : '');
    }
}
