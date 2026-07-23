<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Fixtures;

use Globetrotters\AiPresenceBundle\Client\FetcherInterface;
use Globetrotters\AiPresenceBundle\GlobetrottersAiPresenceBundle;
use Globetrotters\AiPresenceBundle\Tests\Support\FakeFetcher;
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

    public function __construct(
        string $environment = 'test',
        bool $debug = false,
        private readonly bool $withRobotsRoute = false,
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
        return __DIR__.'/var/cache/'.$this->environment.($this->withRobotsRoute ? '_robots' : '');
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
        ]);

        $services = $container->services();
        $services->set(FakeFetcher::class)->public();
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
}
