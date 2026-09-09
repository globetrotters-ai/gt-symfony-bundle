<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Settings;

/**
 * The install profile, mirroring the backend's ``BundleProfile``
 * (``presence/apex/domain/apex_bundle.py``).
 *
 * Only the two that describe *this bundle serving a site* are modelled.
 * ``cdn_only`` — the customer's CDN proxying our origin — is a topology in
 * which no PHP runs at the apex at all, so it has nothing to configure here.
 */
enum Profile: string
{
    /**
     * The apex is the whole presence: artefacts served locally, JSON-LD
     * inlined, nothing pointing anywhere else. The bundle's original and
     * default behaviour.
     */
    case FullApex = 'full_apex';

    /**
     * Both lanes at once, and what the product recommends: the apex keeps
     * serving the same local artefact set *and* links back to the presence
     * published at ``ai.<their-domain>``, so the subdomain stops being an
     * orphan no crawler can reach.
     *
     * The locally served path set is deliberately unchanged from
     * {@see self::FullApex}. The backend's split is an *offload* list
     * (``bundle_builder._MINIMAL_FOOTPRINT_OFFLOAD``) and none of the six paths
     * this bundle serves is on it — the offloaded files are the heavy ones
     * ({@see \Globetrotters\AiPresenceBundle\Serving\ContentTypes} never served
     * them). The difference this profile makes is the breadcrumb, not the
     * footprint.
     */
    case SubdomainBreadcrumb = 'subdomain_breadcrumb';

    /**
     * Resolve a configured value, falling back to the conservative profile.
     * Config validation already rejects unknown values; this keeps a
     * hand-built service definition from turning a typo into a fatal.
     */
    public static function resolve(string $value): self
    {
        return self::tryFrom($value) ?? self::FullApex;
    }
}
