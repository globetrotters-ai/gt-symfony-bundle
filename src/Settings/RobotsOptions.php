<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Settings;

/**
 * How the ``/robots.txt`` block treats the AI agents it names.
 *
 * Both defaults leave what a crawler may fetch exactly as the site already
 * had it. Anything broader is a decision about the customer's whole site, not
 * about the presence, so it is theirs to opt into.
 */
final class RobotsOptions
{
    /** Each named agent inherits the site's own ``User-agent: *`` rules. */
    public const INHERIT = 'inherit';

    /** Each named agent gets ``Allow: /``, overriding the site's wildcard rules for it. */
    public const ALLOW_ALL = 'allow_all';

    public function __construct(
        private readonly string $aiAgents = self::INHERIT,
        private readonly bool $aiTrain = false,
    ) {
    }

    public function allowsAll(): bool
    {
        return self::ALLOW_ALL === $this->aiAgents;
    }

    /**
     * Whether ``Content-Signal`` declares ``ai-train=yes``.
     */
    public function aiTrain(): bool
    {
        return $this->aiTrain;
    }
}
