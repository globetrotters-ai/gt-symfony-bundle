<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Cache;

use Globetrotters\AiPresenceBundle\Cache\CachePoolGuardPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class CachePoolGuardPassTest extends TestCase
{
    public function testAnApcuPoolIsFoundUnderAnyNumberOfWrappers(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('pool.0', new Definition(ApcuAdapter::class));
        for ($i = 1; $i <= 8; ++$i) {
            $class = 0 === $i % 2 ? TraceableAdapter::class : TagAwareAdapter::class;
            $container->setDefinition('pool.'.$i, new Definition($class, [new Reference('pool.'.($i - 1))]));
        }
        $container->setAlias(CachePoolGuardPass::POOL_ALIAS, 'pool.8');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"pool.8" is backed by APCu');

        (new CachePoolGuardPass())->process($container);
    }

    public function testAWrapperCycleEndsTheWalk(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('a', new Definition(TagAwareAdapter::class, [new Reference('b'), new Reference('fs')]));
        $container->setDefinition('b', new Definition(TraceableAdapter::class, [new Reference('a')]));
        $container->setDefinition('fs', new Definition(FilesystemAdapter::class));
        $container->setAlias(CachePoolGuardPass::POOL_ALIAS, 'a');

        (new CachePoolGuardPass())->process($container);

        $this->addToAssertionCount(1);
    }
}
