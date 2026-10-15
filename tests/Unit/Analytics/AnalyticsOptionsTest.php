<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use PHPUnit\Framework\TestCase;

final class AnalyticsOptionsTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('httpsEndpoints')]
    public function testAnHttpsEndpointIsUsed(string $endpoint): void
    {
        $options = self::options($endpoint);

        self::assertSame(trim($endpoint), $options->endpoint());
        self::assertTrue($options->isConfigured());
        self::assertFalse($options->hasRefusedEndpoint());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function httpsEndpoints(): iterable
    {
        yield 'issued' => ['https://api.globetrotters.ai/presence/analytics/server-log'];
        yield 'uppercase scheme' => ['HTTPS://api.globetrotters.ai/presence/analytics/server-log'];
        yield 'port' => ['https://api.globetrotters.ai:8443/ingest'];
        yield 'surrounding whitespace' => ['  https://api.globetrotters.ai/ingest  '];
    }

    /**
     * The endpoint receives the bearer token and every captured client IP, so
     * anything but https reads as unconfigured: nothing is captured, and no
     * lane ever flushes.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedEndpoints')]
    public function testAnyOtherEndpointReadsAsUnconfigured(string $endpoint): void
    {
        $options = self::options($endpoint);

        self::assertSame('', $options->endpoint());
        self::assertFalse($options->isConfigured());
        self::assertTrue($options->hasRefusedEndpoint());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function refusedEndpoints(): iterable
    {
        yield 'cleartext' => ['http://api.globetrotters.ai/presence/analytics/server-log'];
        yield 'uppercase cleartext' => ['HTTP://api.globetrotters.ai/presence/analytics/server-log'];
        yield 'scheme-relative' => ['//api.globetrotters.ai/ingest'];
        yield 'no scheme' => ['api.globetrotters.ai/ingest'];
        yield 'no host' => ['https:///ingest'];
        yield 'no authority' => ['https:api.globetrotters.ai/ingest'];
        yield 'other scheme' => ['ftp://api.globetrotters.ai/ingest'];
        yield 'lookalike scheme' => ['httpss://api.globetrotters.ai/ingest'];
    }

    public function testAnUnsetEndpointIsNotARefusedOne(): void
    {
        self::assertSame('', self::options('')->endpoint());
        self::assertFalse(self::options('')->hasRefusedEndpoint());
        self::assertFalse(self::options('   ')->hasRefusedEndpoint());
    }

    private static function options(string $endpoint): AnalyticsOptions
    {
        return new AnalyticsOptions(true, $endpoint, 'token', true, false);
    }
}
