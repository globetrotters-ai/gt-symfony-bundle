<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\ServedVersion;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ServedVersionTest extends TestCase
{
    private string $dir;
    private BufferDirectory $directory;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
        $this->directory = new BufferDirectory($this->dir.'/presence');
        $this->clock = new MockClock('2026-10-19 04:00:00', 'UTC');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    public function testRecordsWhatTheWebServedAndWhere(): void
    {
        $served = $this->served();
        $served->observe('2026-10-14-104728');

        $record = $served->read();
        self::assertNotNull($record);
        self::assertSame('2026-10-14-104728', $record['version']);
        self::assertSame($this->clock->now()->getTimestamp(), $record['seen_at']);
        self::assertSame('/srv/site', $record['project_dir']);
        self::assertSame('prod', $record['env']);
        self::assertNotSame('', $record['user']);
        self::assertSame('2026-10-14-104728', $served->version());
        self::assertSame(0o660, fileperms($this->directory->path(ServedVersion::FILE)) & 0o777);
    }

    public function testTheSameVersionIsRewrittenEveryFiveMinutes(): void
    {
        $served = $this->served();
        $served->observe('v1');
        $first = $served->read()['seen_at'] ?? null;

        $this->clock->modify('+4 minutes');
        $served->observe('v1');
        self::assertSame($first, $served->read()['seen_at'] ?? null);

        // A web stuck on v1 after a pull shows as such within five minutes.
        $this->clock->modify('+1 minute');
        $served->observe('v1');
        self::assertSame($this->clock->now()->getTimestamp(), $served->read()['seen_at'] ?? null);
    }

    public function testANewVersionIsRecordedAtOnce(): void
    {
        $served = $this->served();
        $served->observe('v1');
        $this->clock->modify('+1 minute');
        $served->observe('v2');

        self::assertSame('v2', $served->version());
    }

    public function testARequestThatStartedEarlierCannotOverwriteALaterObservation(): void
    {
        $served = $this->served();
        $now = $this->clock->now()->getTimestamp();
        $served->observe('v2', $now);
        $served->observe('v1', $now - 5);

        self::assertSame('v2', $served->version());
    }

    public function testNoBundleIsRecordedButReportedAsUnknown(): void
    {
        $served = $this->served();
        $served->observe('');

        self::assertSame('', $served->read()['version'] ?? null);
        self::assertNull($served->version());
    }

    public function testAGarbledRecordReadsAsNone(): void
    {
        $this->directory->ensure();
        file_put_contents($this->directory->path(ServedVersion::FILE), '{"version": 3}');

        self::assertNull($this->served()->read());
    }

    private function served(): ServedVersion
    {
        return new ServedVersion($this->directory, $this->clock, '/srv/site', 'prod');
    }
}
