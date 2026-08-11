<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Analytics\ClientIpResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ClientIpResolverTest extends TestCase
{
    private const PROXY = '10.0.0.1';
    private const AGENT = '160.79.104.10';

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    public function testWithoutTrustedProxiesTheConnectingAddressWins(): void
    {
        // Forwarded headers are attacker-controlled on a direct-to-origin app:
        // honouring them would let any client claim a vendor IP and manufacture
        // a verified hit.
        $request = $this->request(['REMOTE_ADDR' => self::PROXY], [
            'X-Forwarded-For' => self::AGENT,
            'CF-Connecting-IP' => self::AGENT,
        ]);

        self::assertSame(self::PROXY, $this->resolver(true)->resolve($request));
    }

    public function testBehindATrustedProxyTheForwardedClientWins(): void
    {
        Request::setTrustedProxies([self::PROXY], Request::HEADER_X_FORWARDED_FOR);
        $request = $this->request(['REMOTE_ADDR' => self::PROXY], ['X-Forwarded-For' => self::AGENT]);

        self::assertSame(self::AGENT, $this->resolver(false)->resolve($request));
    }

    public function testTheChainIsWalkedOnlyAsFarAsTheTrustedHopsGo(): void
    {
        // The contract says "leftmost entry of X-Forwarded-For", and that is
        // what Symfony returns once every hop is declared — but a leftmost read
        // taken blindly is client-controlled: anyone can prepend an entry and
        // assert a vendor IP. Symfony stops at the first hop it was not told to
        // trust, which is the answer that cannot be forged, so the resolver
        // defers to it rather than reimplementing the walk.
        Request::setTrustedProxies([self::PROXY], Request::HEADER_X_FORWARDED_FOR);
        $forged = $this->request(['REMOTE_ADDR' => self::PROXY], ['X-Forwarded-For' => self::AGENT.', 203.0.113.7']);

        self::assertSame('203.0.113.7', $this->resolver(false)->resolve($forged));

        Request::setTrustedProxies([self::PROXY, '203.0.113.7'], Request::HEADER_X_FORWARDED_FOR);
        $declared = $this->request(['REMOTE_ADDR' => self::PROXY], ['X-Forwarded-For' => self::AGENT.', 203.0.113.7']);

        self::assertSame(self::AGENT, $declared->getClientIp());
        self::assertSame(self::AGENT, $this->resolver(false)->resolve($declared));
    }

    public function testCloudflareHeaderIsUsedOnlyWhenOptedInAndBehindATrustedProxy(): void
    {
        Request::setTrustedProxies([self::PROXY], Request::HEADER_X_FORWARDED_FOR);
        $request = $this->request(
            ['REMOTE_ADDR' => self::PROXY],
            ['CF-Connecting-IP' => self::AGENT, 'X-Forwarded-For' => '203.0.113.7'],
        );

        self::assertSame(self::AGENT, $this->resolver(true)->resolve($request));
        self::assertSame('203.0.113.7', $this->resolver(false)->resolve($request), 'opt-out falls back to the standard chain');
    }

    public function testCloudflareHeaderIsIgnoredWhenTheRequestIsNotFromATrustedProxy(): void
    {
        $request = $this->request(['REMOTE_ADDR' => '198.51.100.4'], ['CF-Connecting-IP' => self::AGENT]);

        self::assertSame('198.51.100.4', $this->resolver(true)->resolve($request));
    }

    public function testAMalformedAddressResolvesToEmptyRatherThanGarbage(): void
    {
        Request::setTrustedProxies([self::PROXY], Request::HEADER_X_FORWARDED_FOR);
        $request = $this->request(['REMOTE_ADDR' => self::PROXY], ['CF-Connecting-IP' => 'not-an-ip']);

        // Falls through to the standard chain, which has nothing usable either.
        self::assertSame(self::PROXY, $this->resolver(true)->resolve($request));
    }

    public function testIpv6IsAccepted(): void
    {
        $request = $this->request(['REMOTE_ADDR' => '2606:4700::6812:1']);

        self::assertSame('2606:4700::6812:1', $this->resolver(false)->resolve($request));
    }

    public function testADirectRequestLooksTrustworthy(): void
    {
        self::assertTrue($this->resolver(false)->looksTrustworthy($this->request(['REMOTE_ADDR' => self::AGENT])));
    }

    public function testAForwardedRequestFromAnUntrustedProxyIsFlagged(): void
    {
        // The silent failure the status command exists to surface: every event
        // would report the proxy's address and land unverified.
        $request = $this->request(['REMOTE_ADDR' => self::PROXY], ['X-Forwarded-For' => self::AGENT]);

        self::assertFalse($this->resolver(false)->looksTrustworthy($request));
    }

    public function testAForwardedRequestFromATrustedProxyIsFine(): void
    {
        Request::setTrustedProxies([self::PROXY], Request::HEADER_X_FORWARDED_FOR);
        $request = $this->request(['REMOTE_ADDR' => self::PROXY], ['X-Forwarded-For' => self::AGENT]);

        self::assertTrue($this->resolver(false)->looksTrustworthy($request));
    }

    private function resolver(bool $trustCloudflare): ClientIpResolver
    {
        return new ClientIpResolver(new AnalyticsOptions(true, 'https://api.test/ingest', 'token', true, $trustCloudflare));
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $headers
     */
    private function request(array $server, array $headers = []): Request
    {
        foreach ($headers as $name => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
        }

        return new Request([], [], [], [], [], $server);
    }
}
