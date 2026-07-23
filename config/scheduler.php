<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Globetrotters\AiPresenceBundle\Scheduler\RefreshMessageHandler;
use Globetrotters\AiPresenceBundle\Scheduler\RefreshScheduleProvider;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Globetrotters\AiPresenceBundle\Sync\ArtefactSync;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(RefreshMessageHandler::class)
        ->args([service(ArtefactSync::class)])
        ->tag('messenger.message_handler');

    $services->set(RefreshScheduleProvider::class)
        ->args([service(Options::class), service('globetrotters_ai_presence.cache_pool')])
        ->tag('scheduler.schedule_provider', ['name' => 'gt']);
};
