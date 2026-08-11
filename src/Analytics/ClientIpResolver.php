<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Analytics;

use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the client IP for a captured event.
 *
 * The contract's chain is ``CF-Connecting-IP`` → leftmost ``X-Forwarded-For``
 * → ``REMOTE_ADDR``, but the forwarded entries may only be believed when the
 * application has declared what sits in front of it. Both headers are
 * attacker-controlled on a direct-to-origin app: honouring them unconditionally
 * would let any client assert an IP inside a published vendor CIDR range and
 * manufacture ``verified=true`` hits — and that flag is the only thing making
 * self-reported counts credible.
 *
 * Symfony already has that opt-in, so this deliberately does not reimplement
 * the chain: with ``framework.trusted_proxies`` configured,
 * ``Request::getClientIp()`` walks ``X-Forwarded-For`` and stops at the first
 * hop it was not told to trust. Where every hop is declared that is the
 * contract's leftmost entry; where one is not, it is the leftmost entry that
 * cannot have been forged — a client can prepend anything it likes to
 * ``X-Forwarded-For``, so a blind leftmost read is precisely how a vendor IP
 * would be spoofed. Only ``CF-Connecting-IP`` is added on top, because it is
 * not part of Symfony's standard forwarded-header set — and only for a request
 * that actually arrived through a trusted proxy.
 *
 * The cost of the safe default is the opposite failure: behind Cloudflare with
 * no trusted proxies configured, every hit reports Cloudflare's address and the
 * backend's forward-confirm fails for the whole install. That case is silent by
 * nature, so {@see looksTrustworthy()} detects it and ``gt:status`` reports it.
 */
final class ClientIpResolver
{
    public const CLOUDFLARE_HEADER = 'CF-Connecting-IP';

    /**
     * Headers whose presence means something in front of us is rewriting the
     * connection's origin.
     */
    private const FORWARDING_HEADERS = ['Forwarded', 'X-Forwarded-For', self::CLOUDFLARE_HEADER];

    public function __construct(private readonly AnalyticsOptions $options)
    {
    }

    /**
     * The client IP, or '' when nothing valid is available.
     */
    public function resolve(Request $request): string
    {
        if ($this->options->trustCloudflareHeader() && $request->isFromTrustedProxy()) {
            $cloudflare = self::validate((string) $request->headers->get(self::CLOUDFLARE_HEADER, ''));
            if ('' !== $cloudflare) {
                return $cloudflare;
            }
        }

        return self::validate((string) $request->getClientIp());
    }

    /**
     * Whether the resolved address is plausibly the agent's own.
     *
     * False means the request carried forwarding headers but did not arrive
     * from a proxy this application trusts — so ``getClientIp()`` returned the
     * proxy's address and every event from this install will land
     * ``verified=false`` at the backend. Nothing anywhere reports that on its
     * own, which is why it is detected here.
     */
    public function looksTrustworthy(Request $request): bool
    {
        if (!self::isForwarded($request)) {
            return true;
        }

        return $request->isFromTrustedProxy();
    }

    private static function isForwarded(Request $request): bool
    {
        foreach (self::FORWARDING_HEADERS as $header) {
            if ($request->headers->has($header)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The value when it is a well-formed IP address, otherwise ''.
     *
     * The backend refuses a non-IP string anyway; dropping it here keeps
     * garbage out of the buffer and off the wire.
     */
    private static function validate(string $value): string
    {
        $value = trim($value);

        return false === filter_var($value, \FILTER_VALIDATE_IP) ? '' : $value;
    }
}
