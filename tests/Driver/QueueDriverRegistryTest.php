<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Driver;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Config\QueueConfiguration;
use Dirthara\Queue\Driver\QueueDriverRegistry;
use Dirthara\Queue\Tests\Fixtures\RecordingQueueDriver;
use Dirthara\Queue\Exception\QueueDriverNotFoundException;
use Dirthara\Queue\Exception\DuplicateQueueDriverException;

final class QueueDriverRegistryTest extends TestCase
{
    #[Test]
    public function it_provides_the_driver_registered_under_a_name(): void
    {
        $registry = new QueueDriverRegistry();
        $memory = new RecordingQueueDriver();
        $redis = new RecordingQueueDriver();

        $registry->register('memory', $memory);
        $registry->register('redis', $redis);

        self::assertSame($memory, $registry->driver('memory'));
        self::assertSame($redis, $registry->driver('redis'));
    }

    #[Test]
    public function it_has_no_drivers_until_one_is_registered(): void
    {
        self::assertFalse(new QueueDriverRegistry()->has('memory'));
    }

    #[Test]
    public function it_has_each_driver_registered_under_a_name(): void
    {
        $registry = new QueueDriverRegistry();
        $registry->register('memory', new RecordingQueueDriver());
        $registry->register('redis', new RecordingQueueDriver());

        self::assertTrue($registry->has('memory'));
        self::assertTrue($registry->has('redis'));
        self::assertFalse($registry->has('database'));
    }

    #[Test]
    public function it_matches_driver_names_exactly_when_asked_whether_it_has_one(): void
    {
        $registry = new QueueDriverRegistry();
        $registry->register('memory', new RecordingQueueDriver());

        self::assertFalse($registry->has('Memory'));
        self::assertFalse($registry->has(' memory'));
        self::assertFalse($registry->has(''));
    }

    #[Test]
    public function it_rejects_a_second_driver_under_the_same_name(): void
    {
        $registry = new QueueDriverRegistry();
        $first = new RecordingQueueDriver();
        $registry->register('memory', $first);

        try {
            $registry->register('memory', new RecordingQueueDriver());
            self::fail('A second driver under the same name was accepted.');
        } catch (DuplicateQueueDriverException $exception) {
            self::assertSame(['driver' => 'memory'], $exception->context);
        }

        self::assertTrue($registry->has('memory'));
        self::assertSame($first, $registry->driver('memory'));
    }

    #[Test]
    public function it_refuses_a_driver_name_without_a_driver(): void
    {
        $registry = new QueueDriverRegistry();
        $registry->register('memory', new RecordingQueueDriver());

        try {
            $registry->driver('redis');
            self::fail('A driver name without a driver was accepted.');
        } catch (QueueDriverNotFoundException $exception) {
            self::assertSame(['driver' => 'redis'], $exception->context);
        }
    }

    #[Test]
    public function it_matches_driver_names_exactly(): void
    {
        $registry = new QueueDriverRegistry();
        $registry->register('memory', new RecordingQueueDriver());

        $this->expectException(QueueDriverNotFoundException::class);

        $registry->driver('Memory');
    }

    #[Test]
    public function it_creates_a_queue_through_the_configured_driver(): void
    {
        $registry = new QueueDriverRegistry();
        $memory = new RecordingQueueDriver();
        $redis = new RecordingQueueDriver();
        $registry->register('memory', $memory);
        $registry->register('redis', $redis);

        $configuration = new QueueConfiguration('redis', ['host' => 'localhost']);

        $registry->create($configuration);

        self::assertSame([$configuration], $redis->configurations);
        self::assertSame([], $memory->configurations);
    }

    #[Test]
    public function it_returns_the_queue_the_driver_creates(): void
    {
        $registry = new QueueDriverRegistry();
        $registry->register('memory', new RecordingQueueDriver());

        $first = $registry->create(new QueueConfiguration('memory'));
        $second = $registry->create(new QueueConfiguration('memory'));

        self::assertNotSame($first, $second);
    }

    #[Test]
    public function it_refuses_to_create_a_queue_for_a_driver_name_without_a_driver(): void
    {
        $this->expectException(QueueDriverNotFoundException::class);

        new QueueDriverRegistry()->create(new QueueConfiguration('redis'));
    }
}
