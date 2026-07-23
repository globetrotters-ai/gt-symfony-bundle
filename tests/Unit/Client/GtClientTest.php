<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Client;

use Globetrotters\AiPresenceBundle\Client\FetcherInterface;
use Globetrotters\AiPresenceBundle\Client\GtClient;
use Globetrotters\AiPresenceBundle\GlobetrottersAiPresenceBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GtClientTest extends TestCase
{
    public function testSuccessfulFetch(): void
    {
        $client = new GtClient(new MockHttpClient(new MockResponse('artefact body')));
        $result = $client->fetch('https://x.example/llms.txt');

        self::assertTrue($result->isOk());
        self::assertSame('artefact body', $result->body());
    }

    public function testNon200PassedThrough(): void
    {
        $client = new GtClient(new MockHttpClient(new MockResponse('missing', ['http_code' => 404])));
        $result = $client->fetch('https://x.example/llms.txt');

        self::assertFalse($result->isOk());
        self::assertFalse($result->isTransportError());
        self::assertSame(404, $result->status());
    }

    public function testTransportErrorNormalized(): void
    {
        $client = new GtClient(new MockHttpClient(new MockResponse('', ['error' => 'network down'])));
        $result = $client->fetch('https://x.example/llms.txt');

        self::assertTrue($result->isTransportError());
        self::assertFalse($result->isOk());
        self::assertStringContainsString('network down', $result->errorMessage());
    }

    public function testOversizeBodyArrivesDetectablyTruncated(): void
    {
        $oversize = str_repeat('x', FetcherInterface::MAX_BODY_BYTES + 100000);
        $client = new GtClient(new MockHttpClient(new MockResponse($oversize)));
        $result = $client->fetch('https://x.example/llms.txt');

        self::assertSame(200, $result->status());
        self::assertSame(FetcherInterface::MAX_BODY_BYTES + 1, \strlen($result->body()));
    }

    public function testBodyAtLimitArrivesIntact(): void
    {
        $body = str_repeat('x', FetcherInterface::MAX_BODY_BYTES);
        $client = new GtClient(new MockHttpClient(new MockResponse($body)));
        $result = $client->fetch('https://x.example/llms.txt');

        self::assertSame($body, $result->body());
    }

    public function testSendsUserAgentAndAccept(): void
    {
        $seen = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'headers' => $options['normalized_headers']];

            return new MockResponse('ok');
        });

        (new GtClient($mock))->fetch('https://x.example/ai.json');

        self::assertSame('GET', $seen['method']);
        self::assertSame('https://x.example/ai.json', $seen['url']);
        self::assertContains(
            'User-Agent: GlobetrottersAiPresence/'.GlobetrottersAiPresenceBundle::VERSION,
            $seen['headers']['user-agent'],
        );
        self::assertContains('Accept: */*', $seen['headers']['accept']);
    }
}
