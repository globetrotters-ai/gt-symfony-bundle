<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Schedule;

/**
 * Composition root: config tree + service wiring. The extension alias derived
 * from the class name is "globetrotters_ai_presence", the bundle config key.
 */
final class GlobetrottersAiPresenceBundle extends AbstractBundle
{
    public const VERSION = '0.2.0';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('website_url')
                    ->info('The published Globetrotters subdomain to pull artefacts from, e.g. https://your-site.globetrotters.ai')
                    ->defaultValue('')
                ->end()
                ->enumNode('refresh_interval')
                    ->values(['daily', 'weekly'])
                    ->defaultValue('daily')
                ->end()
                ->scalarNode('cache_pool')
                    ->info('PSR-6 cache pool service id used for the artefact bundle and sync state. For stateful symfony/scheduler runs the pool must also implement Symfony\Contracts\Cache\CacheInterface (the default cache.app does); a PSR-6-only pool still works but the schedule falls back to non-stateful.')
                    ->defaultValue('cache.app')
                ->end()
                ->scalarNode('homepage_path')
                    ->info('Path where the JSON-LD head injection applies')
                    ->defaultValue('/')
                ->end()
                ->arrayNode('reporting')
                    ->info('Reports agent traffic to the six served artefact paths back to Globetrotters. An apex install is pull-and-cache, so those requests terminate in this application and are invisible without it.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Reporting still needs both credentials below; this only switches off a configured install.')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('endpoint')
                            ->info('Ingest endpoint URL, issued with the token on the Studio apex install screen. Bind to an env var.')
                            ->defaultValue('')
                        ->end()
                        ->scalarNode('ingest_token')
                            ->info('Per-install ingest token, shown exactly once. Bind to an env var (%env(GLOBETROTTERS_INGEST_TOKEN)%) so it can live in the Secrets vault.')
                            ->defaultValue('')
                        ->end()
                        ->scalarNode('buffer_dir')
                            ->info('Directory for the local event buffer, its drop counter and the flush lock. Must be writable by both the web user and whoever runs the flush.')
                            ->defaultValue('%kernel.project_dir%/var/globetrotters-ai-presence')
                        ->end()
                        ->booleanNode('opportunistic_flush')
                            ->info('Flush on kernel.terminate (after the response is sent) at most every 15 minutes, for deployments with no cron and no Messenger worker.')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('trust_cloudflare_header')
                            ->info('Resolve the client IP from CF-Connecting-IP. Only honoured for requests arriving from a framework.trusted_proxies entry, since the header is otherwise forgeable.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array{
     *     website_url: string,
     *     refresh_interval: string,
     *     cache_pool: string,
     *     homepage_path: string,
     *     reporting: array{
     *         enabled: bool,
     *         endpoint: string,
     *         ingest_token: string,
     *         buffer_dir: string,
     *         opportunistic_flush: bool,
     *         trust_cloudflare_header: bool,
     *     },
     * } $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('globetrotters_ai_presence.website_url', $config['website_url'])
            ->set('globetrotters_ai_presence.refresh_interval', $config['refresh_interval'])
            ->set('globetrotters_ai_presence.homepage_path', $config['homepage_path'])
            ->set('globetrotters_ai_presence.reporting.buffer_dir', $config['reporting']['buffer_dir']);

        $container->services()->alias('globetrotters_ai_presence.cache_pool', $config['cache_pool']);

        // The ingest token is a credential, so it is wired straight into the
        // one service that needs it rather than being published as a container
        // parameter — a parameter would put it in the compiled container's
        // parameter bag and in `debug:container --parameters`. Bound to an env
        // var (the documented setup) the value here is only ever the
        // unresolved %env()% placeholder, and this keeps it that way even for
        // an integrator who pastes the literal.
        $container->services()
            ->set(AnalyticsOptions::class)
            ->args([
                $config['reporting']['enabled'],
                $config['reporting']['endpoint'],
                $config['reporting']['ingest_token'],
                $config['reporting']['opportunistic_flush'],
                $config['reporting']['trust_cloudflare_header'],
            ]);

        $container->import('../config/services.php');
        $container->import('../config/analytics.php');

        if (class_exists(Schedule::class) && interface_exists(MessageBusInterface::class)) {
            $container->import('../config/scheduler.php');
        }

        if (class_exists(\Twig\Extension\AbstractExtension::class)) {
            $container->import('../config/twig.php');
        }
    }
}
