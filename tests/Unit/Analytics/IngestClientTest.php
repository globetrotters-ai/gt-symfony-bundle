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
}
