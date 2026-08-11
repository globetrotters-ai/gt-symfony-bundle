<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\AnalyticsState;
use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\ClientIpResolver;
use Globetrotters\AiPresenceBundle\Analytics\DroppedCounter;
use Globetrotters\AiPresenceBundle\Analytics\EventBuffer;
use Globetrotters\AiPresenceBundle\Analytics\EventRecorder;
use Globetrotters\AiPresenceBundle\Analytics\EventStoreInterface;
use Globetrotters\AiPresenceBundle\Analytics\Flusher;
use Globetrotters\AiPresenceBundle\Analytics\FlushGate;
use Globetrotters\AiPresenceBundle\Analytics\IngestClient;
use Globetrotters\AiPresenceBundle\Analytics\IngestTransportInterface;
use Globetrotters\AiPresenceBundle\Analytics\NdjsonEventStore;
use Globetrotters\AiPresenceBundle\Command\PresenceFlushCommand;
use Globetrotters\AiPresenceBundle\Serving\ArtefactCaptureSubscriber;
use Globetrotters\AiPresenceBundle\Serving\ArtefactHeaderSubscriber;
use Globetrotters\AiPresenceBundle\Serving\OpportunisticFlushSubscriber;

/*
 * Server-log reporting lane. AnalyticsOptions is deliberately absent: it
 * carries the ingest token and is wired in the bundle's loadExtension() so the
 * credential never becomes a container parameter.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(BufferDirectory::class)
        ->args([param('globetrotters_ai_presence.reporting.buffer_dir')]);

    $services->set(NdjsonEventStore::class)
        ->args([service(BufferDirectory::class)]);

    $services->alias(EventStoreInterface::class, NdjsonEventStore::class);

    $services->set(DroppedCounter::class)
        ->args([service(BufferDirectory::class)]);

    $services->set(EventBuffer::class)
        ->args([service(EventStoreInterface::class), service(DroppedCounter::class)]);

    $services->set(AnalyticsState::class)
        ->args([service('globetrotters_ai_presence.cache_pool')])
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set(ClientIpResolver::class)
        ->args([service(AnalyticsOptions::class)]);

    $services->set(EventRecorder::class)
        ->args([
            service(EventBuffer::class),
            service(AnalyticsOptions::class),
            service(ClientIpResolver::class),
            service(AnalyticsState::class),
            service('globetrotters_ai_presence.clock'),
        ]);

    // Same SSRF-guarded client the pull side uses: the endpoint is a
    // Globetrotters host, but the configured value is pasted by hand.
    $services->set(IngestClient::class)
        ->args([service('globetrotters_ai_presence.http_client')]);

    $services->alias(IngestTransportInterface::class, IngestClient::class);

    $services->set(FlushGate::class)
        ->args([service(BufferDirectory::class), service('globetrotters_ai_presence.clock')]);

    $services->set(Flusher::class)
        ->args([
            service(EventBuffer::class),
            service(IngestTransportInterface::class),
            service(AnalyticsOptions::class),
            service(AnalyticsState::class),
            service(FlushGate::class),
            service('globetrotters_ai_presence.clock'),
        ]);

    $services->set(ArtefactHeaderSubscriber::class)
        ->tag('kernel.event_subscriber');

    $services->set(ArtefactCaptureSubscriber::class)
        ->args([service(EventRecorder::class)])
        ->tag('kernel.event_subscriber');

    $services->set(OpportunisticFlushSubscriber::class)
        ->args([
            service(Flusher::class),
            service(EventBuffer::class),
            service(AnalyticsOptions::class),
            service(FlushGate::class),
        ])
        ->tag('kernel.event_subscriber');

    $services->set(PresenceFlushCommand::class)
        ->args([
            service(Flusher::class),
            service(EventBuffer::class),
            service(AnalyticsOptions::class),
            service(AnalyticsState::class),
            service(FlushGate::class),
        ])
        ->tag('console.command');
};
