<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Cache;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * The bundle pool's logger: keeps the last failure the adapter reported, so a
 * refresh that could not store its bundle can say why (``fopen(…): Permission
 * denied`` and the path), and forwards everything to the application's logger.
 *
 * Symfony's adapters report a failed write by logging it and returning false;
 * without a logger the reason is lost, which is all ``Failed to persist`` had
 * to go on before.
 */
final class PoolFailureLog extends AbstractLogger
{
    private string $last = '';

    public function __construct(private readonly ?LoggerInterface $inner = null)
    {
    }

    /**
     * A mixed $message: wider than psr/log 2 and 3 declare it, and compatible
     * with 1, which Symfony 6.4 still allows.
     *
     * @param array<mixed> $context
     */
    public function log(mixed $level, mixed $message, array $context = []): void
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (\is_scalar($value) || $value instanceof \Stringable) {
                $replace['{'.$key.'}'] = (string) $value;
            }
        }
        $this->last = strtr(\is_scalar($message) || $message instanceof \Stringable ? (string) $message : '', $replace);

        $this->inner?->log($level, $message, $context);
    }

    /**
     * Start a new attempt: forget what an earlier one reported.
     */
    public function clear(): void
    {
        $this->last = '';
    }

    public function last(): string
    {
        return $this->last;
    }
}
