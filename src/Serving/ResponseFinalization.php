<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

/**
 * Whether this PHP runtime has already delivered the response by the time
 * kernel.terminate runs.
 *
 * Symfony's ``Response::send()`` ends a request early only through
 * ``fastcgi_finish_request()`` or ``litespeed_finish_request()``, and only where
 * one exists: PHP-FPM, FrankenPHP (which defines ``fastcgi_finish_request()`` as
 * an alias of ``frankenphp_finish_request()``), and LiteSpeed's LSAPI.
 * Everywhere else — Apache mod_php, the CLI development server, a long-running
 * worker that bridges its own responses — the client is still waiting while
 * terminate listeners run, so network work there lands in the visitor's
 * response time.
 *
 * Probed per call rather than at container compile time: the container is
 * usually compiled by a CLI process, which has neither function.
 */
final class ResponseFinalization
{
    private const FINISHERS = ['fastcgi_finish_request', 'litespeed_finish_request'];

    /**
     * @param bool|null $finishesEarly fixes the answer instead of probing the
     *                                 runtime; null (the default) probes
     */
    public function __construct(private readonly ?bool $finishesEarly = null)
    {
    }

    public function finishesBeforeTerminate(): bool
    {
        if (null !== $this->finishesEarly) {
            return $this->finishesEarly;
        }

        foreach (self::FINISHERS as $function) {
            if (\function_exists($function)) {
                return true;
            }
        }

        return false;
    }
}
