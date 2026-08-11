<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\AnalyticsState;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\EventBuffer;
use Globetrotters\AiPresenceBundle\Analytics\FlushGate;
use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Client\FetcherInterface;
use Globetrotters\AiPresenceBundle\Client\GtClient;
use Globetrotters\AiPresenceBundle\Command\RefreshCommand;
use Globetrotters\AiPresenceBundle\Command\StatusCommand;
use Globetrotters\AiPresenceBundle\Serving\HeadInjector;
use Globetrotters\AiPresenceBundle\Serving\RobotsFilter;
use Globetrotters\AiPresenceBundle\Serving\Router;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Globetrotters\AiPresenceBundle\Sync\ArtefactSync;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // The configured website_url is untrusted input: refuse URLs that resolve
    // to private/reserved IPs, including across redirects.
    $services->set('globetrotters_ai_presence.http_client', NoPrivateNetworkHttpClient::class)
        ->args([service('http_client')]);

    $services->set('globetrotters_ai_presence.clock', NativeClock::class);

    $services->set(GtClient::class)
        ->args([service('globetrotters_ai_presence.http_client')]);

    $services->alias(FetcherInterface::class, GtClient::class);

    $services->set(Options::class)
        ->args([
            service('globetrotters_ai_presence.cache_pool'),
            param('globetrotters_ai_presence.website_url'),
            param('globetrotters_ai_presence.refresh_interval'),
            param('globetrotters_ai_presence.homepage_path'),
        ])
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set(ArtefactCache::class)
        ->args([service('globetrotters_ai_presence.cache_pool')])
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set(ArtefactSync::class)
        ->args([
            service(FetcherInterface::class),
            service(ArtefactCache::class),
            service(Options::class),
            service('globetrotters_ai_presence.clock'),
        ]);

    $services->set(Router::class)
        ->args([service(ArtefactCache::class)])
        ->tag('kernel.event_subscriber');

    $services->set(HeadInjector::class)
        ->args([service(ArtefactCache::class), service(Options::class)])
        ->tag('kernel.event_subscriber');

    $services->set(RobotsFilter::class)
        ->args([service(Options::class), service(ArtefactCache::class)])
        ->tag('kernel.event_subscriber');

    $services->set(RefreshCommand::class)
        ->args([
            service(ArtefactSync::class),
            service(Options::class),
            service('globetrotters_ai_presence.clock'),
        ])
        ->tag('console.command');

    $services->set(StatusCommand::class)
        ->args([
            service(Options::class),
            service(ArtefactCache::class),
            service(ArtefactSync::class),
            service(AnalyticsOptions::class),
            service(AnalyticsState::class),
            service(EventBuffer::class),
            service(FlushGate::class),
            service(BufferDirectory::class),
        ])
        ->tag('console.command');
};
