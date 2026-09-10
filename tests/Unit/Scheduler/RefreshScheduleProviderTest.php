<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Scheduler;

use Globetrotters\AiPresenceBundle\Scheduler\FlushMessage;
use Globetrotters\AiPresenceBundle\Scheduler\RefreshMessage;
use Globetrotters\AiPresenceBundle\Scheduler\RefreshScheduleProvider;
use Globetrotters\AiPresenceBundle\Settings\Options;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

/**
 * Builds the real schedule, on whichever Symfony the suite resolved. The CI
 * matrix runs this on 6.4 and 7.4, which is the point: the provider is only
 * ever constructed by a Messenger worker, so nothing else in the suite would
 * notice a Schedule method one of them lacks.
 */
final class RefreshScheduleProviderTest extends TestCase
{
    public function testBuildsTheScheduleOnThisSymfonyVersion(): void
    {
        $pool = new ArrayAdapter();

        $schedule = $this->provider($pool)->getSchedule();

        $messages = array_map(
            static fn (RecurringMessage $message): string => $message->getTrigger().' → '.self::messageClass($message),
            array_values($schedule->getRecurringMessages()),
        );
        self::assertSame(['every 1 day → '.RefreshMessage::class, 'every 5 minutes → '.FlushMessage::class], $messages);
        self::assertSame($pool, $schedule->getState());
    }

    public function testWeeklyRefreshIntervalIsHonoured(): void
    {
        $schedule = $this->provider(new ArrayAdapter(), 'weekly')->getSchedule();

        self::assertSame('every 1 week', (string) array_values($schedule->getRecurringMessages())[0]->getTrigger());
    }

    /**
     * Symfony 7.1+ can collapse the runs a stopped worker missed; 6.4 cannot,
     * and there the replayed runs are absorbed by the handlers themselves (the
     * flush interval is enforced under the flush lock, a refresh is an
     * idempotent re-pull).
     */
    public function testCollapsesMissedRunsWhereTheSchedulerSupportsIt(): void
    {
        $schedule = $this->provider(new ArrayAdapter())->getSchedule();

        if (!method_exists(Schedule::class, 'shouldProcessOnlyLastMissedRun')) {
            self::assertFalse(method_exists($schedule, 'processOnlyLastMissedRun'), 'this Symfony has no missed-run collapse to enable');

            return;
        }

        self::assertTrue($schedule->shouldProcessOnlyLastMissedRun());
    }

    public function testAPsr6OnlyPoolDegradesToANonStatefulSchedule(): void
    {
        $schedule = $this->provider(new Psr6OnlyPool())->getSchedule();

        self::assertNull($schedule->getState());
        self::assertCount(2, $schedule->getRecurringMessages());
    }

    /**
     * The schedule a worker actually consumes, not just the object: after a
     * gap longer than one flush poll, the generator yields both messages.
     */
    public function testAWorkerGeneratesBothMessages(): void
    {
        $clock = new MockClock();
        $generator = new MessageGenerator($this->provider(new ArrayAdapter()), 'gt', $clock);

        iterator_to_array($generator->getMessages(), false);
        $clock->sleep(86400 + 60);

        $classes = array_map(static fn (object $message): string => $message::class, iterator_to_array($generator->getMessages(), false));

        self::assertContains(RefreshMessage::class, $classes);
        self::assertContains(FlushMessage::class, $classes);
    }

    private function provider(CacheItemPoolInterface $pool, string $interval = 'daily'): RefreshScheduleProvider
    {
        return new RefreshScheduleProvider(new Options($pool, 'https://nantes.globetrotters.ai', $interval, '/'), $pool);
    }

    private static function messageClass(RecurringMessage $message): string
    {
        foreach ($message->getProvider()->getMessages(new \Symfony\Component\Scheduler\Generator\MessageContext('gt', $message->getId(), $message->getTrigger(), new \DateTimeImmutable())) as $inner) {
            return $inner::class;
        }

        return '';
    }
}

/**
 * The bundle only promises a PSR-6 pool; this is one with none of the Symfony
 * cache contracts that stateful() needs.
 */
final class Psr6OnlyPool implements CacheItemPoolInterface
{
    private ArrayAdapter $inner;

    public function __construct()
    {
        $this->inner = new ArrayAdapter();
    }

    public function getItem(string $key): CacheItemInterface
    {
        return $this->inner->getItem($key);
    }

    public function getItems(array $keys = []): iterable
    {
        return $this->inner->getItems($keys);
    }

    public function hasItem(string $key): bool
    {
        return $this->inner->hasItem($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function deleteItem(string $key): bool
    {
        return $this->inner->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->inner->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->inner->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->inner->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }
}
