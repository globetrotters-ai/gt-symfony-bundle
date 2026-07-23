<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Serving\ContentTypes;
use PHPUnit\Framework\TestCase;

final class ContentTypesTest extends TestCase
{
    public function testCanonicalMap(): void
    {
        self::assertSame([
            'llms.txt' => 'text/plain; charset=utf-8',
            'ai.json' => 'application/json; charset=utf-8',
            'schema.json' => 'application/ld+json; charset=utf-8',
            '.well-known/mcp.json' => 'application/json; charset=utf-8',
            '.well-known/agent-card.json' => 'application/json; charset=utf-8',
            '.well-known/globetrotters-apex-version.json' => 'application/json; charset=utf-8',
        ], array_combine(ContentTypes::paths(), array_map(
            static fn (string $path): ?string => ContentTypes::forPath($path),
            ContentTypes::paths(),
        )));
    }

    public function testVersionMarkerConstant(): void
    {
        self::assertSame('.well-known/globetrotters-apex-version.json', ContentTypes::VERSION_MARKER);
        self::assertTrue(ContentTypes::has(ContentTypes::VERSION_MARKER));
    }

    public function testUnknownPathMisses(): void
    {
        self::assertFalse(ContentTypes::has('llms-full.txt'));
        self::assertFalse(ContentTypes::has('content.md'));
        self::assertFalse(ContentTypes::has('index.html'));
        self::assertNull(ContentTypes::forPath('nope.txt'));
    }

    public function testPathsHaveNoLeadingSlash(): void
    {
        foreach (ContentTypes::paths() as $path) {
            self::assertStringStartsNotWith('/', $path);
        }
    }
}
