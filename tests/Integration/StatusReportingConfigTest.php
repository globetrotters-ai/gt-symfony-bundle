<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

/**
 * An install with no ``reporting`` block: the env vars Studio's screen asks
 * for are read only once the bundle config binds them, so ``gt:status`` has to
 * say where the missing piece goes rather than a bare "not set".
 */
final class StatusReportingConfigTest extends IntegrationTestCase
{
    public function testAnUnboundConfigNamesTheFileToEdit(): void
    {
        $this->bootClient();

        $display = $this->runCommand('gt:status')->getDisplay();
        $flat = preg_replace('/\s+/', ' ', $display) ?? $display;

        self::assertStringContainsString('not in config', $flat);
        self::assertStringContainsString('config/packages/globetrotters_ai_presence.yaml', $flat);
        self::assertStringContainsString("'%env(GLOBETROTTERS_INGEST_ENDPOINT)%'", $flat);
        self::assertStringContainsString('cache:clear', $flat);
    }
}
