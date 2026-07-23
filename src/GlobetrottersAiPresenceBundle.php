<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle;

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
    public const VERSION = '0.1.0';

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
                    ->info('PSR-6 cache pool service id used for the artefact bundle and sync state')
                    ->defaultValue('cache.app')
                ->end()
                ->scalarNode('homepage_path')
                    ->info('Path where the JSON-LD head injection applies')
                    ->defaultValue('/')
                ->end()
            ->end();
    }

    /**
     * @param array{website_url: string, refresh_interval: string, cache_pool: string, homepage_path: string} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('globetrotters_ai_presence.website_url', $config['website_url'])
            ->set('globetrotters_ai_presence.refresh_interval', $config['refresh_interval'])
            ->set('globetrotters_ai_presence.homepage_path', $config['homepage_path']);

        $container->services()->alias('globetrotters_ai_presence.cache_pool', $config['cache_pool']);

        $container->import('../config/services.php');

        if (class_exists(Schedule::class) && interface_exists(MessageBusInterface::class)) {
            $container->import('../config/scheduler.php');
        }

        if (class_exists(\Twig\Extension\AbstractExtension::class)) {
            $container->import('../config/twig.php');
        }
    }
}
