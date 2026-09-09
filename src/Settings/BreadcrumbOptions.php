<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Settings;

/**
 * The install profile and the breadcrumb settings that depend on it, kept
 * beside {@see Options} rather than inside it so the serving path can ask one
 * small object "does this install link back, and how".
 */
final class BreadcrumbOptions
{
    private readonly Profile $profile;

    public function __construct(
        string $profile,
        private readonly string $anchorText,
    ) {
        $this->profile = Profile::resolve($profile);
    }

    public function profile(): Profile
    {
        return $this->profile;
    }

    public function isBreadcrumb(): bool
    {
        return Profile::SubdomainBreadcrumb === $this->profile;
    }

    /**
     * Configured anchor text for ``{{ gt_ai_presence_breadcrumb_link() }}``, or
     * '' to accept the derived default. Empty is the documented way to say "use
     * the destination name".
     */
    public function anchorText(): string
    {
        return trim($this->anchorText);
    }
}
