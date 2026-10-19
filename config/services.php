<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Globetrotters\AiPresenceBundle\Analytics\AiReferrers;
use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\AnalyticsState;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\EventBuffer;
use Globetrotters\AiPresenceBundle\Analytics\FlushGate;
use Globetrotters\AiPresenceBundle\Analytics\PageViewCounter;
use Globetrotters\AiPresenceBundle\Analytics\PageViewOptions;
use Globetrotters\AiPresenceBundle\Analytics\ServedVersion;
use Globetrotters\AiPresenceBundle\Analytics\VendorRanges;
use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Cache\LegacyPool;
use Globetrotters\AiPresenceBundle\Cache\PoolFailureLog;
use Globetrotters\AiPresenceBundle\Cache\SharedFilesystemAdapter;
use Globetrotters\AiPresenceBundle\Client\FetcherInterface;
use Globetrotters\AiPresenceBundle\Client\GtClient;
use Globetrotters\AiPresenceBundle\Command\RefreshCommand;
use Globetrotters\AiPresenceBundle\Command\StatusCommand;
use Globetrotters\AiPresenceBundle\GlobetrottersAiPresenceBundle;
use Globetrotters\AiPresenceBundle\Serving\ApiCatalog;
use Globetrotters\AiPresenceBundle\Serving\BreadcrumbInjector;
use Globetrotters\AiPresenceBundle\Serving\BreadcrumbRenderer;
use Globetrotters\AiPresenceBundle\Serving\ConditionalGetSubscriber;
use Globetrotters\AiPresenceBundle\Serving\HeadInjector;
use Globetrotters\AiPresenceBundle\Serving\LinkHeader;
use Globetrotters\AiPresenceBundle\Serving\RobotsFilter;
use Globetrotters\AiPresenceBundle\Serving\Router;
use Globetrotters\AiPresenceBundle\Serving\Sitemap;
use Globetrotters\AiPresenceBundle\Settings\BreadcrumbOptions;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Globetrotters\AiPresenceBundle\Settings\RobotsOptions;
use Globetrotters\AiPresenceBundle\Sync\ArtefactSync;
use Globetrotters\AiPresenceBundle\Sync\RefreshFailure;
use Globetrotters\AiPresenceBundle\Sync\RefreshLock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // The configured website_url is untrusted input: refuse URLs that resolve
    // to private/reserved IPs, including across redirects.
    $services->set('globetrotters_ai_presence.http_client', NoPrivateNetworkHttpClient::class)
        ->args([service('http_client')]);

    $services->set('globetrotters_ai_presence.clock', NativeClock::class);

    // The default cache_pool: one every process of the app reads, whatever
    // cache.app is. See SharedFilesystemAdapter.
    $services->set(GlobetrottersAiPresenceBundle::CACHE_POOL, SharedFilesystemAdapter::class)
        ->args([param('globetrotters_ai_presence.cache_dir')])
        ->call('setLogger', [service(PoolFailureLog::class)]);

    // Keeps the reason a write was refused, for the refresh's error, and
    // passes everything on to the app's logger.
    $services->set(PoolFailureLog::class)
        ->args([service('logger')->nullOnInvalid()])
        ->tag('monolog.logger', ['channel' => 'cache']);

    $services->set(GtClient::class)
        ->args([service('globetrotters_ai_presence.http_client')]);

    $services->alias(FetcherInterface::class, GtClient::class);

    $services->set(Options::class)
        ->args([
            service('globetrotters_ai_presence.cache_pool'),
            param('globetrotters_ai_presence.website_url'),
            param('globetrotters_ai_presence.refresh_interval'),
            param('globetrotters_ai_presence.homepage_path'),
            service(LegacyPool::class)->nullOnInvalid(),
        ])
        ->tag('kernel.reset', ['method' => 'reset']);

    // The cache only serves a bundle pulled from this same website_url, so a
    // cleared or repointed install stops publishing the previous source.
    $services->set(ArtefactCache::class)
        ->args([
            service('globetrotters_ai_presence.cache_pool'),
            param('globetrotters_ai_presence.website_url'),
            service(LegacyPool::class)->nullOnInvalid(),
        ])
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set(ArtefactSync::class)
        ->args([
            service(FetcherInterface::class),
            service(ArtefactCache::class),
            service(Options::class),
            service('globetrotters_ai_presence.clock'),
            param('kernel.project_dir'),
            param('kernel.environment'),
            service(RefreshFailure::class),
            service(PoolFailureLog::class),
        ]);

    // Outside the pool: a pool that refused the bundle refuses this too.
    $services->set(RefreshFailure::class)
        ->args([service(BufferDirectory::class), param('kernel.project_dir'), param('kernel.environment')]);

    $services->set(RefreshLock::class)
        ->args([service(BufferDirectory::class)]);

    $services->set(ApiCatalog::class)
        ->args([service(ArtefactCache::class)]);

    $services->set(Sitemap::class)
        ->args([service(Options::class), service(ArtefactCache::class)]);

    $services->set(Router::class)
        ->args([service(ArtefactCache::class), service(Options::class), service(Sitemap::class), service(ApiCatalog::class)])
        ->tag('kernel.event_subscriber');

    $services->set(HeadInjector::class)
        ->args([service(ArtefactCache::class), service(Options::class)])
        ->tag('kernel.event_subscriber');

    $services->set(LinkHeader::class)
        ->args([service(ArtefactCache::class), service(Options::class)])
        ->tag('kernel.event_subscriber');

    $services->set(BreadcrumbOptions::class)
        ->args([
            param('globetrotters_ai_presence.profile'),
            param('globetrotters_ai_presence.breadcrumb.anchor_text'),
        ]);

    $services->set(BreadcrumbRenderer::class)
        ->args([
            service(ArtefactCache::class),
            service(BreadcrumbOptions::class),
            service(Options::class),
            service('request_stack'),
        ]);

    $services->set(BreadcrumbInjector::class)
        ->args([service(BreadcrumbRenderer::class)])
        ->tag('kernel.event_subscriber');

    $services->set(ConditionalGetSubscriber::class)
        ->tag('kernel.event_subscriber');

    $services->set(RobotsOptions::class)
        ->args([
            param('globetrotters_ai_presence.robots.ai_agents'),
            param('globetrotters_ai_presence.robots.ai_train'),
        ]);

    $services->set(RobotsFilter::class)
        ->args([service(Options::class), service(ArtefactCache::class), service(RobotsOptions::class)])
        ->tag('kernel.event_subscriber');

    $services->set(RefreshCommand::class)
        ->args([
            service(ArtefactSync::class),
            service(Options::class),
            service(VendorRanges::class),
            service(RefreshLock::class),
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
            service(PageViewOptions::class),
            service(PageViewCounter::class),
            service(VendorRanges::class),
            service(AiReferrers::class),
            service(ServedVersion::class),
            service(RefreshFailure::class),
        ])
        ->tag('console.command');
};
