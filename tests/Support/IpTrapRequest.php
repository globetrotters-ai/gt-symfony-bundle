<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Support;

use Symfony\Component\HttpFoundation\Request;

/**
 * A request that fails the test the moment anything asks it for a client IP.
 *
 * The page-view counter promises it never reads one; asserting on what it
 * stored only proves it did not *keep* an IP, this proves it never looked.
 */
final class IpTrapRequest extends Request
{
    public function getClientIp(): ?string
    {
        throw new \LogicException('The client IP was read.');
    }

    public function getClientIps(): array
    {
        throw new \LogicException('The client IPs were read.');
    }
}
