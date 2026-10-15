<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Analytics\AnalyticsOptions;
use Globetrotters\AiPresenceBundle\Tests\Fixtures\TestKernel;

/**
 * An install whose config declares no ``reporting`` keys, only the
 * ``GLOBETROTTERS_INGEST_*`` variables Studio's install screen hands over:
 * the bundle reads them by default, and a missing one is "not set", never a
 * container error.
 */
final class StatusReportingConfigTest extends IntegrationTestCase
{
    protected static bool $withReportingFromEnv = true;

    private const VARS = ['GLOBETROTTERS_INGEST_ENDPOINT', 'GLOBETROTTERS_INGEST_TOKEN'];

    protected function tearDown(): void
    {
        foreach (self::VARS as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        parent::tearDown();
    }

    public function testTheVariablesAloneTurnReportingOn(): void
    {
        $this->setEnv('GLOBETROTTERS_INGEST_ENDPOINT', TestKernel::INGEST_ENDPOINT);
        $this->setEnv('GLOBETROTTERS_INGEST_TOKEN', TestKernel::INGEST_TOKEN);
        $this->bootClient();

        $options = static::getContainer()->get(AnalyticsOptions::class);
        \assert($options instanceof AnalyticsOptions);
        self::assertTrue($options->isConfigured());

        $display = $this->runCommand('gt:status')->getDisplay();
        self::assertStringContainsString(TestKernel::INGEST_ENDPOINT, $display);
        self::assertStringContainsString('…abcd', $display);
        self::assertStringNotContainsString(TestKernel::INGEST_TOKEN, $display);
    }

    public function testAMissingVariableIsNotSetRatherThanAnError(): void
    {
        $this->setEnv('GLOBETROTTERS_INGEST_ENDPOINT', TestKernel::INGEST_ENDPOINT);
        $this->bootClient();

        $flat = preg_replace('/\s+/', ' ', $this->runCommand('gt:status')->getDisplay()) ?? '';

        self::assertStringContainsString('Ingest token not set', $flat);
        self::assertStringContainsString('GLOBETROTTERS_INGEST_TOKEN is not set', $flat);
        // Only the variable that is missing is named.
        self::assertStringNotContainsString('GLOBETROTTERS_INGEST_ENDPOINT is not set', $flat);
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
}
