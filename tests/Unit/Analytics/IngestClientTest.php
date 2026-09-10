<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\IngestClient;
use Globetrotters\AiPresenceBundle\Analytics\IngestResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IngestClientTest extends TestCase
{
    public function testOnlyTheContractStatusIsAccepted(): void
    {
        self::assertTrue(IngestResult::http(202)->isAccepted());
        self::assertFalse(IngestResult::http(200)->isAccepted());
        self::assertFalse(IngestResult::http(204)->isAccepted());
        self::assertFalse(IngestResult::http(302)->isAccepted());
    }

    public function testPostDoesNotFollowRedirects(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'max_redirects' => $options['max_redirects']];

            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://example.com/login']]);
        });

        $result = (new IngestClient($http))->post('https://api.example.test/ingest', 'secret', '{"events":[]}');

        self::assertSame('POST', $seen['method']);
        self::assertSame('https://api.example.test/ingest', $seen['url']);
        self::assertSame(0, $seen['max_redirects']);
        self::assertSame(302, $result->status());
        self::assertFalse($result->isAccepted());
    }

    /**
     * AnalyticsOptions already reads such an endpoint as unconfigured, so this
     * is a caller that went around it. Nothing may leave: not the bearer token,
     * not a batch of client IPs. The mock would accept, so a missing guard
     * shows up as an accepted flush.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedEndpoints')]
    public function testPostRefusesANonHttpsEndpointBeforeSending(string $url): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 202]));

        $result = (new IngestClient($http))->post($url, 'secret-token', '{"events":[]}');

        self::assertSame(0, $http->getRequestsCount());
        self::assertFalse($result->isAccepted());
        self::assertSame(0, $result->status());
        self::assertSame('the ingest endpoint must be an https:// URL', $result->errorMessage());
        self::assertStringNotContainsString('secret-token', $result->errorMessage());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function refusedEndpoints(): iterable
    {
        yield 'cleartext' => ['http://api.example.test/ingest'];
        yield 'scheme-relative' => ['//api.example.test/ingest'];
        yield 'no host' => ['https:api.example.test/ingest'];
        yield 'empty' => [''];
    }
}
