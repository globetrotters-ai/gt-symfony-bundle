<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\GlobetrottersAiPresenceBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\Compiler\ValidateEnvPlaceholdersPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

/**
 * The config tree, run through the same passes a kernel build runs.
 */
final class GlobetrottersAiPresenceBundleTest extends TestCase
{
    private const ENDPOINT = 'https://api.globetrotters.ai/presence/analytics/server-log';

    protected function tearDown(): void
    {
        // The merge pass leaves its env placeholder prefix in static state.
        BaseNode::resetPlaceholders();
    }

    public function testAnHttpsEndpointBuilds(): void
    {
        $container = $this->build(['reporting' => ['endpoint' => self::ENDPOINT]]);

        self::assertSame(self::ENDPOINT, $container->getDefinition(AnalyticsOptions::class)->getArgument(1));
    }

    public function testAnUnsetEndpointBuilds(): void
    {
        $container = $this->build([]);

        self::assertSame('', $container->getDefinition(AnalyticsOptions::class)->getArgument(1));
    }

    /**
     * A literal cleartext endpoint is a mistake the build can name, rather
     * than a reporting lane that silently never starts.
     */
    public function testACleartextEndpointFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/"globetrotters_ai_presence\.reporting\.endpoint".*must be an https:\/\/ URL/');

        $this->build(['reporting' => ['endpoint' => 'http://api.globetrotters.ai/presence/analytics/server-log']]);
    }

    /**
     * The documented setup: the value is only resolved at runtime, where
     * AnalyticsOptions::endpoint() is the check that holds, so the build must
     * not refuse the placeholder.
     */
    public function testAnEnvBoundEndpointBuilds(): void
    {
        $container = $this->build(['reporting' => ['endpoint' => '%env(GLOBETROTTERS_INGEST_ENDPOINT)%']]);

        self::assertTrue($container->hasDefinition(AnalyticsOptions::class));
    }

    /**
     * An env() default is known at build, so it is held to the same rule.
     */
    public function testACleartextEnvDefaultFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/must be an https:\/\/ URL/');

        $this->build(
            ['reporting' => ['endpoint' => '%env(GLOBETROTTERS_INGEST_ENDPOINT)%']],
            ['env(GLOBETROTTERS_INGEST_ENDPOINT)' => 'http://api.globetrotters.ai/presence/analytics/server-log'],
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $parameters
     */
    private function build(array $config, array $parameters = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->getParameterBag()->add($parameters + [
            'kernel.environment' => 'test',
            'kernel.build_dir' => sys_get_temp_dir(),
        ]);

        $extension = (new GlobetrottersAiPresenceBundle())->getContainerExtension();
        self::assertInstanceOf(ExtensionInterface::class, $extension);
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), $config);

        (new MergeExtensionConfigurationPass())->process($container);
        (new ValidateEnvPlaceholdersPass())->process($container);

        return $container;
    }
}
