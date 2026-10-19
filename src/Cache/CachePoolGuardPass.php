<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Cache;

use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\ProxyAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Refuses, at container build, a ``cache_pool`` that the CLI and the web
 * server cannot share.
 *
 * ``gt:refresh`` runs from cron or a Messenger worker and PHP-FPM serves, so
 * the pool is the only thing carrying a pulled bundle from one to the other.
 * APCu and the array adapter are memory private to one process: the refresh
 * succeeds, reports the new version, and writes where the web never reads, so
 * the apex serves the bundle it had for good. ``cache.system`` puts an APCu
 * tier in front of its files wherever APCu is enabled, with the same result.
 * Nothing at runtime can tell this apart from a healthy install, so it fails
 * the build instead — a deploy with this configuration never goes live.
 *
 * Runs once decorators and parent definitions are resolved, and looks through
 * the wrappers Symfony puts around a pool (tracing in debug, tags, chains).
 */
final class CachePoolGuardPass implements CompilerPassInterface
{
    public const POOL_ALIAS = 'globetrotters_ai_presence.cache_pool';

    public const README_ANCHOR = 'https://github.com/globetrotters-ai/gt-symfony-bundle#cache-pool';

    private const UNSHARED = [
        ApcuAdapter::class => 'APCu (memory private to each PHP process)',
        ArrayAdapter::class => 'the in-memory array adapter (memory private to each PHP process)',
    ];

    private const WRAPPERS = [
        TraceableAdapter::class,
        TagAwareAdapter::class,
        ChainAdapter::class,
        ProxyAdapter::class,
    ];

    private const MAX_DEPTH = 4;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasAlias(self::POOL_ALIAS) || !$container->has(self::POOL_ALIAS)) {
            return;
        }

        $configured = (string) $container->getAlias(self::POOL_ALIAS);
        $kind = $this->unsharedKind($container, $container->findDefinition(self::POOL_ALIAS), 0);
        if (null === $kind) {
            return;
        }

        throw new InvalidConfigurationException(\sprintf('globetrotters_ai_presence.cache_pool "%s" is backed by %s, so the CLI or worker that runs gt:refresh and the web server that serves the artefacts would not share it: a refresh would succeed and the apex would keep serving the bundle it had. Remove the cache_pool setting to use the bundle\'s own pool (globetrotters_ai_presence.cache, a filesystem pool under var/globetrotters-ai-presence/cache), or point it at a filesystem or Redis pool every process reads. See %s', $configured, $kind, self::README_ANCHOR));
    }

    private function unsharedKind(ContainerBuilder $container, Definition $definition, int $depth): ?string
    {
        $factory = $definition->getFactory();
        if (\is_array($factory) && 'createSystemCache' === ($factory[1] ?? null)) {
            return 'cache.system, which puts an APCu tier (memory private to each PHP process) in front of its files';
        }

        $class = $container->getParameterBag()->resolveValue($definition->getClass());
        if (!\is_string($class) || '' === $class) {
            return null;
        }

        foreach (self::UNSHARED as $unshared => $kind) {
            if (is_a($class, $unshared, true)) {
                return $kind;
            }
        }

        if ($depth >= self::MAX_DEPTH || [] === array_filter(self::WRAPPERS, static fn (string $wrapper): bool => is_a($class, $wrapper, true))) {
            return null;
        }

        // A chain is only as shared as its least-shared tier: reads stop at
        // the first hit, so a process-private tier in front serves its own
        // copy whatever the tiers behind it hold.
        foreach (self::inner($definition->getArguments()) as $inner) {
            if ($inner instanceof Reference) {
                if (!$container->has((string) $inner)) {
                    continue;
                }
                $inner = $container->findDefinition((string) $inner);
            }
            $kind = $this->unsharedKind($container, $inner, $depth + 1);
            if (null !== $kind) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * The pools a wrapper's arguments name: by reference, or inline (a
     * chain's tiers are inline definitions).
     *
     * @param array<mixed> $arguments
     *
     * @return list<Reference|Definition>
     */
    private static function inner(array $arguments): array
    {
        $inner = [];
        foreach ($arguments as $argument) {
            if ($argument instanceof Reference || $argument instanceof Definition) {
                $inner[] = $argument;
            } elseif (\is_array($argument)) {
                $inner = array_merge($inner, self::inner($argument));
            }
        }

        return $inner;
    }
}
