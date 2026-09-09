<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Serving\IndexNowKey;
use PHPUnit\Framework\TestCase;

final class IndexNowKeyTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('usableKeys')]
    public function testAWellFormedKeySurvivesSanitizing(string $key): void
    {
        self::assertSame($key, IndexNowKey::sanitize($key));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function usableKeys(): iterable
    {
        yield 'ours (32 hex)' => ['e715a2e7bf3c4a1d8e0b6f9c2d5a7e14'];
        yield 'shortest' => ['abcdefgh'];
        yield 'longest' => [str_repeat('a', 128)];
        yield 'mixed case' => ['Abc-DEF-123'];
    }

    /**
     * A key outside IndexNow's own grammar cannot verify a host, so it reads as
     * unconfigured rather than as something to serve — the same posture as the
     * backend's ``deploy_config_renderer._INDEXNOW_KEY_RE``.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableKeys')]
    public function testAnUnusableKeySanitizesToEmpty(mixed $raw): void
    {
        self::assertSame('', IndexNowKey::sanitize($raw));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function unusableKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['abcdefg'];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'underscore' => ['abcdefg_h'];
        yield 'dot' => ['abcdefg.h'];
        // A slash would let a stored key match an interior path such as
        // '.well-known/x.txt', and is not part of IndexNow's grammar.
        yield 'slash' => ['abcd/efgh'];
        // PHP's '$' also matches before a trailing newline, so an anchored
        // pattern without \z would accept a tfvars value with a stray one.
        yield 'trailing newline' => ["abcdefgh\n"];
        yield 'leading space' => [' abcdefgh'];
        yield 'not a string' => [['abcdefgh']];
        yield 'null' => [null];
    }

    public function testAKeyFilePathIsTheKeyPlusTxt(): void
    {
        self::assertSame('abcdefgh.txt', IndexNowKey::pathFor('abcdefgh'));
    }

    public function testAnUnusableKeyHasNoPath(): void
    {
        self::assertSame('', IndexNowKey::pathFor('nope'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('candidates')]
    public function testCandidateFromPath(string $path, string $expected): void
    {
        self::assertSame($expected, IndexNowKey::candidateFromPath($path));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function candidates(): iterable
    {
        yield 'key file' => ['abcdefgh.txt', 'abcdefgh'];
        // Every served artefact: none can ever be read as a key candidate,
        // which is what keeps /llms.txt resolving to the artefact.
        yield 'llms.txt' => ['llms.txt', ''];
        yield 'ai.json' => ['ai.json', ''];
        yield 'robots.txt' => ['robots.txt', ''];
        yield 'well-known key' => ['.well-known/abcdefgh.txt', ''];
        yield 'wrong extension' => ['abcdefgh.text', ''];
        yield 'bare .txt' => ['.txt', ''];
        yield 'empty' => ['', ''];
    }
}
