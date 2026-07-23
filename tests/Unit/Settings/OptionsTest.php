<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Settings;

use Globetrotters\AiPresenceBundle\Settings\Options;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class OptionsTest extends TestCase
{
    private function options(string $url = 'https://nantes.globetrotters.ai', string $interval = 'daily'): Options
    {
        return new Options(new ArrayAdapter(), $url, $interval, '/');
    }

    public function testNormalizeUrl(): void
    {
        self::assertSame('https://x.example', Options::normalizeUrl('  https://x.example/  '));
        self::assertSame('https://x.example', Options::normalizeUrl('https://x.example///'));
        self::assertSame('', Options::normalizeUrl('   '));
    }

    public function testBaseUrlNormalizesConfiguredValue(): void
    {
        self::assertSame(
            'https://nantes.globetrotters.ai',
            $this->options('https://nantes.globetrotters.ai/ ')->baseUrl(),
        );
    }

    public function testSlugIsFirstHostLabel(): void
    {
        self::assertSame('nantes', $this->options()->slug());
        self::assertSame('nantes', $this->options('https://NANTES.globetrotters.ai')->slug());
        self::assertSame('', $this->options('')->slug());
    }

    public function testIsConnected(): void
    {
        self::assertTrue($this->options()->isConnected());
        self::assertFalse($this->options('')->isConnected());
        self::assertFalse($this->options('   ')->isConnected());
    }

    public function testRefreshInterval(): void
    {
        self::assertSame('daily', $this->options()->refreshInterval());
        self::assertSame(86400, $this->options()->refreshIntervalSeconds());
        self::assertSame('weekly', $this->options(interval: 'weekly')->refreshInterval());
        self::assertSame(604800, $this->options(interval: 'weekly')->refreshIntervalSeconds());
    }

    public function testStateDefaults(): void
    {
        self::assertSame([
            'installed_version' => '',
            'latest_version' => '',
            'content_hash' => '',
            'last_refresh' => 0,
            'last_error' => '',
        ], $this->options()->state());
    }

    public function testUpdateStateMergesAndPersists(): void
    {
        $pool = new ArrayAdapter();
        $options = new Options($pool, 'https://x.example', 'daily', '/');
        $options->updateState(['installed_version' => 'v1', 'last_refresh' => 123]);

        // A fresh instance on the same pool sees the persisted state.
        $fresh = new Options($pool, 'https://x.example', 'daily', '/');
        $state = $fresh->state();
        self::assertSame('v1', $state['installed_version']);
        self::assertSame(123, $state['last_refresh']);
        self::assertSame('', $state['last_error']);
    }

    public function testResetDropsMemo(): void
    {
        $pool = new ArrayAdapter();
        $a = new Options($pool, 'https://x.example', 'daily', '/');
        $b = new Options($pool, 'https://x.example', 'daily', '/');

        $a->state();
        $b->updateState(['installed_version' => 'v2']);

        // Without reset the memo hides the write from the other instance.
        self::assertSame('', $a->state()['installed_version']);
        $a->reset();
        self::assertSame('v2', $a->state()['installed_version']);
    }
}
