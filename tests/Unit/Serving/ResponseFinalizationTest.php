<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Serving\ResponseFinalization;
use PHPUnit\Framework\TestCase;

final class ResponseFinalizationTest extends TestCase
{
    /**
     * Exactly the functions Response::send() calls to end a request early —
     * PHP-FPM and FrankenPHP both provide fastcgi_finish_request().
     */
    public function testProbesForTheFunctionsResponseSendUses(): void
    {
        $expected = \function_exists('fastcgi_finish_request') || \function_exists('litespeed_finish_request');

        self::assertSame($expected, (new ResponseFinalization())->finishesBeforeTerminate());
    }

    public function testTheCliSapiNeverFinishesEarly(): void
    {
        if (!\in_array(\PHP_SAPI, ['cli', 'phpdbg'], true)) {
            self::markTestSkipped('Only meaningful when the suite runs under the CLI SAPI.');
        }

        self::assertFalse((new ResponseFinalization())->finishesBeforeTerminate());
    }

    public function testTheAnswerCanBePinned(): void
    {
        self::assertTrue((new ResponseFinalization(true))->finishesBeforeTerminate());
        self::assertFalse((new ResponseFinalization(false))->finishesBeforeTerminate());
    }
}
