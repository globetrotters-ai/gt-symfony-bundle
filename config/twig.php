<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Twig\AiPresenceExtension;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(AiPresenceExtension::class)
        ->args([service(ArtefactCache::class)])
        ->tag('twig.extension');
};
