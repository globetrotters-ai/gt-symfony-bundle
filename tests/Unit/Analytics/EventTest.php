<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\Event;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testClipsStringFieldsAtTheContractLength(): void
    {
        $event = new Event('id', '2026-08-11T09:00:00Z', '/llms.txt', str_repeat('u', 900), '1.2.3.4', str_repeat('r', 900), 200, 10);

        self::assertSame(Event::MAX_FIELD_CHARS, \strlen($event->ua()));
        self::assertSame(Event::MAX_FIELD_CHARS, \strlen($event->referer()));
    }

    public function testEmptyOptionalFieldsGoOutAsNull(): void
    {
        $payload = (new Event('id', '2026-08-11T09:00:00Z', '/ai.json', '', '', '', 200, 0))->toPayload();

        self::assertNull($payload['ua']);
        self::assertNull($payload['ip']);
        self::assertNull($payload['referer']);
    }

    public function testPayloadCarriesTheContractFields(): void
    {
        $payload = (new Event('9f2c', '2026-08-11T09:14:22Z', '/llms.txt', 'ClaudeBot/1.0', '160.79.104.10', 'https://x.test', 200, 4211))->toPayload();

        self::assertSame([
            'id' => '9f2c',
            'ts' => '2026-08-11T09:14:22Z',
            'path' => '/llms.txt',
            'ua' => 'ClaudeBot/1.0',
            'ip' => '160.79.104.10',
            'referer' => 'https://x.test',
            'status' => 200,
            'bytes' => 4211,
        ], $payload);
    }

    public function testALineRoundTripsThroughTheBuffer(): void
    {
        $event = new Event('9f2c', '2026-08-11T09:14:22Z', '/llms.txt', 'ClaudeBot/1.0', '::1', '', 200, 4211);

        $decoded = json_decode($event->toLine(), true);
        self::assertIsArray($decoded);

        self::assertSame($event->toPayload(), Event::fromArray($decoded)->toPayload());
    }

    public function testAMalformedUserAgentStillEncodes(): void
    {
        // A User-Agent is recorded verbatim, and a hostile one can carry bytes
        // that are not valid UTF-8 — which json_encode refuses outright. The
        // event must survive that: losing the hit is the failure this feature
        // exists to prevent.
        $event = new Event('id', '2026-08-11T09:00:00Z', '/llms.txt', "bad\xB1\x31utf8", '', '', 200, 1);

        self::assertNotSame('', $event->toLine());
        self::assertIsArray(json_decode($event->toLine(), true));
    }

    public function testSlashesInAPathAreNotEscaped(): void
    {
        $line = (new Event('id', '2026-08-11T09:00:00Z', '/.well-known/mcp.json', '', '', '', 200, 1))->toLine();

        self::assertStringContainsString('/.well-known/mcp.json', $line);
    }
}
