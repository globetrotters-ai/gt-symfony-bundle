<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Analytics;

use Globetrotters\AiPresenceBundle\Analytics\BufferDirectory;
use Globetrotters\AiPresenceBundle\Analytics\PageViewCounter;
use Globetrotters\AiPresenceBundle\Analytics\PageViewRules;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

final class PageViewCounterTest extends TestCase
{
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private string $dir;
    private PageViewCounter $counter;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::make();
        $this->counter = new PageViewCounter(new BufferDirectory($this->dir));
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    public function testIncrementsPerDayPathAndBucket(): void
    {
        self::assertTrue($this->counter->increment('2026-09-26', '/a', PageViewRules::BUCKET_BROWSER));
        $this->counter->increment('2026-09-26', '/a', PageViewRules::BUCKET_BROWSER);
        $this->counter->increment('2026-09-26', '/a', PageViewRules::BUCKET_AI_BROWSER);
        $this->counter->increment('2026-09-26', '/b', PageViewRules::BUCKET_BROWSER);
        $this->counter->increment('2026-09-27', '/a', PageViewRules::BUCKET_BROWSER);

        self::assertSame([
            "browser\t/a" => 2,
            "ai_browser\t/a" => 1,
            "browser\t/b" => 1,
        ], $this->counter->counts('2026-09-26'));
        self::assertSame(["browser\t/a" => 1], $this->counter->counts('2026-09-27'));
        self::assertFileExists($this->dir.'/pageviews-2026-09-26.json');
    }

    public function testThe2001stDistinctPathOfADayGoesToOther(): void
    {
        for ($i = 0; $i < PageViewCounter::MAX_PATHS_PER_DAY; ++$i) {
            $this->counter->increment('2026-09-26', '/p'.$i, PageViewRules::BUCKET_BROWSER);
        }

        $this->counter->increment('2026-09-26', '/new-path', PageViewRules::BUCKET_BROWSER);
        $this->counter->increment('2026-09-26', '/another', PageViewRules::BUCKET_AI_BROWSER);
        // A path already counted keeps counting under its own name.
        $this->counter->increment('2026-09-26', '/p0', PageViewRules::BUCKET_BROWSER);
        // …and in a bucket it had not been seen in: the cap is on paths, not keys.
        $this->counter->increment('2026-09-26', '/p1', PageViewRules::BUCKET_AI_BROWSER);

        $counts = $this->counter->counts('2026-09-26');
        self::assertArrayNotHasKey("browser\t/new-path", $counts);
        self::assertSame(1, $counts["browser\t(other)"]);
        self::assertSame(1, $counts["ai_browser\t(other)"]);
        self::assertSame(2, $counts["browser\t/p0"]);
        self::assertSame(1, $counts["ai_browser\t/p1"]);
    }

    public function testAnUnusableDirectoryDegradesWithoutThrowing(): void
    {
        $file = $this->dir.'/not-a-dir';
        file_put_contents($file, 'x');
        $counter = new PageViewCounter(new BufferDirectory($file.'/nested'));

        self::assertFalse($counter->increment('2026-09-26', '/a', PageViewRules::BUCKET_BROWSER));
        self::assertSame([], $counter->counts('2026-09-26'));
        self::assertSame(0, $counter->seal('2026-09-27'));
        self::assertSame([], $counter->pending(10));
        self::assertSame(0, $counter->remove(['x']));
        self::assertFalse($counter->hasReportable('2026-09-27'));
    }

    public function testSealsOnlyClosedDays(): void
    {
        $this->counter->increment('2026-09-25', '/a', PageViewRules::BUCKET_BROWSER);
        $this->counter->increment('2026-09-26', '/b', PageViewRules::BUCKET_BROWSER);
        $this->counter->increment('2026-09-27', '/today', PageViewRules::BUCKET_BROWSER);

        self::assertSame(2, $this->counter->seal('2026-09-27'));

        $pending = $this->counter->pending(10);
        self::assertSame(['2026-09-25', '2026-09-26'], array_column($pending, 'day'));
        self::assertSame(['/a', '/b'], array_column($pending, 'path'));
        self::assertSame([1, 1], array_column($pending, 'count'));
        self::assertSame(['browser', 'browser'], array_column($pending, 'bucket'));
        foreach ($pending as $record) {
            self::assertMatchesRegularExpression(self::UUID_V4, $record['id']);
        }
        // Today is still open, and still counting.
        self::assertSame(["browser\t/today" => 1], $this->counter->counts('2026-09-27'));
        self::assertFileDoesNotExist($this->dir.'/pageviews-2026-09-26.json');
    }

    public function testSealingAssignsIdsOnceSoARetryResendsTheSameIds(): void
    {
        $this->counter->increment('2026-09-26', '/a', PageViewRules::BUCKET_BROWSER);
        $this->counter->seal('2026-09-27');
        $first = $this->counter->pending(10);

        // A second flush (the retry after a rejected one) seals again first.
        self::assertSame(0, $this->counter->seal('2026-09-27'));
        self::assertSame($first, $this->counter->pending(10));

        // And a fresh object over the same directory reads the same records.
        self::assertSame($first, (new PageViewCounter(new BufferDirectory($this->dir)))->pending(10));
    }

    /**
     * A request that computed its day just before midnight can increment after
     * that day was sealed. Those views are added as new records rather than
     * overwriting (and re-identifying) the ones already sealed.
     */
    public function testALateIncrementAfterSealingAddsRecordsWithoutTouchingSealedOnes(): void
    {
        $this->counter->increment('2026-09-26', '/a', PageViewRules::BUCKET_BROWSER);
        $this->counter->seal('2026-09-27');
        $sealed = $this->counter->pending(10);

        $this->counter->increment('2026-09-26', '/a', PageViewRules::BUCKET_BROWSER);
        $this->counter->seal('2026-09-27');

        $pending = $this->counter->pending(10);
        self::assertCount(2, $pending);
        self::assertSame($sealed[0], $pending[0]);
        self::assertNotSame($sealed[0]['id'], $pending[1]['id']);
        self::assertSame(1, $pending[1]['count']);
    }

    /**
     * The crash window: the sealed file was written but the process died
     * before the open file was unlinked. Re-sealing it under fresh UUIDs would
     * make the backend (which dedupes on id) count the day twice.
     */
    public function testReSealingAnOpenFileThatWasAlreadySealedAddsNothing(): void
    {
        $this->counter->increment('2026-09-26', '/a', PageViewRules::BUCKET_BROWSER);
        $this->counter->increment('2026-09-26', '/b', PageViewRules::BUCKET_AI_BROWSER);
        $open = $this->dir.'/pageviews-2026-09-26.json';
        clearstatcache(true, $open);
        $stat = stat($open);
        self::assertIsArray($stat);
        $source = PageViewCounter::fingerprint($stat, (string) file_get_contents($open));

        // What the sealed file looks like after the rename, before the unlink.
        $sealed = [
            ['id' => '00000000-0000-4000-8000-000000000001', 'day' => '2026-09-26', 'path' => '/a', 'bucket' => 'browser', 'count' => 1, 'src' => $source],
            ['id' => '00000000-0000-4000-8000-000000000002', 'day' => '2026-09-26', 'path' => '/b', 'bucket' => 'ai_browser', 'count' => 1, 'src' => $source],
        ];
        file_put_contents($this->dir.'/pageviews-sealed-2026-09-26.json', json_encode($sealed));

        self::assertSame(0, $this->counter->seal('2026-09-27'));

        self::assertSame(
            ['00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002'],
            array_column($this->counter->pending(10), 'id'),
        );
        self::assertFileDoesNotExist($open, 'the already-sealed open file is removed');
    }

    /**
     * Linux (ext4, tmpfs) reuses a freed inode at once, and mtime is to the
     * second: a late-view file recreated right after sealing can match the
     * sealed one on inode, mtime *and* bytes. Each open file's random
     * generation is what tells them apart, so the late views still seal.
     */
    public function testALateFileWithTheSameInodeMtimeAndCountsStillSeals(): void
    {
        $this->counter->increment('2026-09-26', '/x', PageViewRules::BUCKET_BROWSER);
        $open = $this->dir.'/pageviews-2026-09-26.json';
        clearstatcache(true, $open);
        $stat = stat($open);
        self::assertIsArray($stat);
        $source = PageViewCounter::fingerprint($stat, (string) file_get_contents($open));
        file_put_contents($this->dir.'/pageviews-sealed-2026-09-26.json', json_encode([
            ['id' => '00000000-0000-4000-8000-000000000001', 'day' => '2026-09-26', 'path' => '/x', 'bucket' => 'browser', 'count' => 1, 'src' => $source],
        ]));

        // The same inode (rewritten in place), the same mtime, the same counts
        // — only the generation differs, as for a file created afresh.
        $late = (array) json_decode((string) file_get_contents($open), true);
        self::assertArrayHasKey(PageViewCounter::GENERATION_KEY, $late, 'a new open file carries a generation');
        ++$late[PageViewCounter::GENERATION_KEY];
        file_put_contents($open, json_encode((object) $late));
        touch($open, $stat['mtime']);
        clearstatcache(true, $open);
        self::assertSame($stat['ino'], stat($open)['ino'] ?? null);

        self::assertSame(1, $this->counter->seal('2026-09-27'));

        $pending = $this->counter->pending(10);
        self::assertCount(2, $pending);
        self::assertSame('00000000-0000-4000-8000-000000000001', $pending[0]['id']);
        self::assertSame(['/x', '/x'], array_column($pending, 'path'));
    }

    public function testTheGenerationIsNeitherACountNorAPath(): void
    {
        $this->counter->increment('2026-09-26', '/a', PageViewRules::BUCKET_BROWSER);
        $this->counter->increment('2026-09-26', '/a', PageViewRules::BUCKET_BROWSER);

        self::assertSame(["browser\t/a" => 2], $this->counter->counts('2026-09-26'));
        self::assertSame(2, $this->counter->openViews());

        $this->counter->seal('2026-09-27');
        self::assertCount(1, $this->counter->pending(10));
    }

    public function testTheGenerationIsSetOnceForTheLifeOfAnOpenFile(): void
    {
        $open = $this->dir.'/pageviews-2026-09-26.json';
        $this->counter->increment('2026-09-26', '/a', PageViewRules::BUCKET_BROWSER);
        $first = (array) json_decode((string) file_get_contents($open), true);
        $this->counter->increment('2026-09-26', '/b', PageViewRules::BUCKET_BROWSER);
        $second = (array) json_decode((string) file_get_contents($open), true);

        self::assertIsInt($first[PageViewCounter::GENERATION_KEY]);
        self::assertSame($first[PageViewCounter::GENERATION_KEY], $second[PageViewCounter::GENERATION_KEY]);
    }

    public function testTheSourceFingerprintIsNeverSent(): void
    {
        $this->counter->increment('2026-09-26', '/a', PageViewRules::BUCKET_BROWSER);
        $this->counter->seal('2026-09-27');

        $raw = json_decode((string) file_get_contents($this->dir.'/pageviews-sealed-2026-09-26.json'), true);
        self::assertIsArray($raw);
        self::assertIsString($raw[0]['src'], 'the sealed file carries the fingerprint');
        self::assertSame(['id', 'day', 'path', 'bucket', 'count'], array_keys($this->counter->pending(10)[0]));
    }

    public function testRemovingSomeRecordsKeepsTheFingerprintOnTheRest(): void
    {
        $this->counter->increment('2026-09-26', '/a', PageViewRules::BUCKET_BROWSER);
        $this->counter->increment('2026-09-26', '/b', PageViewRules::BUCKET_BROWSER);
        $this->counter->seal('2026-09-27');

        $this->counter->remove([$this->counter->pending(1)[0]['id']]);

        $raw = json_decode((string) file_get_contents($this->dir.'/pageviews-sealed-2026-09-26.json'), true);
        self::assertIsArray($raw);
        self::assertCount(1, $raw);
        self::assertIsString($raw[0]['src']);
    }

    public function testDiscardAllRemovesEveryPageViewFile(): void
    {
        $this->counter->increment('2026-09-25', '/a', PageViewRules::BUCKET_BROWSER);
        $this->counter->seal('2026-09-26');
        $this->counter->increment('2026-09-26', '/b', PageViewRules::BUCKET_BROWSER);
        file_put_contents($this->dir.'/events.ndjson', "{}\n");

        self::assertSame(2, $this->counter->discardAll());

        self::assertSame([], glob($this->dir.'/pageviews-*') ?: []);
        self::assertFileExists($this->dir.'/events.ndjson', 'the event buffer is not page-view data');
    }

    public function testRemovesOnlyTheGivenIds(): void
    {
        $this->counter->increment('2026-09-25', '/a', PageViewRules::BUCKET_BROWSER);
        $this->counter->increment('2026-09-26', '/b', PageViewRules::BUCKET_BROWSER);
        $this->counter->increment('2026-09-26', '/c', PageViewRules::BUCKET_BROWSER);
        $this->counter->seal('2026-09-27');
        $pending = $this->counter->pending(10);

        self::assertSame(2, $this->counter->remove([$pending[0]['id'], $pending[1]['id']]));

        self::assertSame([$pending[2]], $this->counter->pending(10));
        self::assertFileDoesNotExist($this->dir.'/pageviews-sealed-2026-09-25.json', 'an emptied day is deleted');
    }

    public function testPendingHonoursTheLimitOldestDayFirst(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->counter->increment('2026-09-26', '/b'.$i, PageViewRules::BUCKET_BROWSER);
            $this->counter->increment('2026-09-25', '/a'.$i, PageViewRules::BUCKET_BROWSER);
        }
        $this->counter->seal('2026-09-27');

        $pending = $this->counter->pending(7);

        self::assertCount(7, $pending);
        self::assertSame(array_merge(array_fill(0, 5, '2026-09-25'), ['2026-09-26', '2026-09-26']), array_column($pending, 'day'));
        self::assertSame(10, $this->counter->pendingRecords());
    }

    /**
     * The backend accepts yesterday back to seven days ago, and drops the rest:
     * sending an older day only wastes the batch, so it is deleted instead.
     */
    public function testPrunesDaysTheBackendWouldDrop(): void
    {
        $this->counter->increment('2026-09-19', '/too-old', PageViewRules::BUCKET_BROWSER);
        $this->counter->increment('2026-09-20', '/oldest-kept', PageViewRules::BUCKET_BROWSER);
        $this->counter->seal('2026-09-21');
        $this->counter->increment('2026-09-18', '/too-old-open', PageViewRules::BUCKET_BROWSER);

        self::assertSame(2, $this->counter->prune('2026-09-27'));

        self::assertSame(['/oldest-kept'], array_column($this->counter->pending(10), 'path'));
        self::assertSame([], $this->counter->counts('2026-09-18'));
    }

    public function testReportsWhetherAnythingIsReportable(): void
    {
        self::assertFalse($this->counter->hasReportable('2026-09-27'));

        $this->counter->increment('2026-09-27', '/today', PageViewRules::BUCKET_BROWSER);
        self::assertFalse($this->counter->hasReportable('2026-09-27'), 'today is still open');
        self::assertSame(1, $this->counter->openViews());

        $this->counter->increment('2026-09-26', '/a', PageViewRules::BUCKET_BROWSER);
        self::assertTrue($this->counter->hasReportable('2026-09-27'), 'a closed day waiting to be sealed');

        $this->counter->seal('2026-09-27');
        self::assertTrue($this->counter->hasReportable('2026-09-27'), 'a sealed record waiting to be sent');

        $this->counter->remove(array_column($this->counter->pending(10), 'id'));
        self::assertFalse($this->counter->hasReportable('2026-09-27'));
    }

    /**
     * Privacy by construction: what lands on disk is a day, a path, a bucket
     * and a count — nothing that identifies a visitor. The one other field is
     * ``src``, a sha1 of the random generation of the open file it was sealed
     * from, which is bookkeeping about a file, not a visitor.
     */
    public function testWritesNothingButDayPathBucketAndCount(): void
    {
        $this->counter->increment('2026-09-26', '/a', PageViewRules::BUCKET_BROWSER);
        $this->counter->seal('2026-09-27');

        $files = array_values(array_diff(scandir($this->dir) ?: [], ['.', '..']));
        self::assertSame(['pageviews-sealed-2026-09-26.json'], $files);

        $records = json_decode((string) file_get_contents($this->dir.'/'.$files[0]), true);
        self::assertIsArray($records);
        self::assertSame(['id', 'day', 'path', 'bucket', 'count', 'src'], array_keys($records[0]));
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $records[0]['src']);
    }
}
