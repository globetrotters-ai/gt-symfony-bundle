<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Client;

use Globetrotters\AiPresenceBundle\Client\FetchResult;
use PHPUnit\Framework\TestCase;

final class FetchResultTest extends TestCase
{
    public function testHttpOk(): void
    {
        $result = FetchResult::http(200, 'body');

        self::assertTrue($result->isOk());
        self::assertFalse($result->isTransportError());
        self::assertSame(200, $result->status());
        self::assertSame('body', $result->body());
        self::assertSame('', $result->errorMessage());
    }

    public function testEmptyBodyIsStillOk(): void
    {
        self::assertTrue(FetchResult::http(200, '')->isOk());
    }

    public function testNon200IsNotOk(): void
    {
        self::assertFalse(FetchResult::http(404, '')->isOk());
        self::assertFalse(FetchResult::http(500, 'oops')->isOk());
    }

    public function testTransportError(): void
    {
        $result = FetchResult::error('DNS failure');

        self::assertFalse($result->isOk());
        self::assertTrue($result->isTransportError());
        self::assertSame(0, $result->status());
        self::assertSame('DNS failure', $result->errorMessage());
    }
}
