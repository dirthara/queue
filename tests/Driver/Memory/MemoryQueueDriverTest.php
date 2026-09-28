<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Driver\Memory;

use PHPUnit\Framework\TestCase;
use Dirthara\Queue\QueuedMessage;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Config\QueueConfiguration;
use Dirthara\Queue\Driver\Memory\InMemoryQueue;
use Dirthara\Queue\Driver\Memory\MemoryQueueDriver;

final class MemoryQueueDriverTest extends TestCase
{
    #[Test]
    public function it_creates_an_in_memory_queue(): void
    {
        self::assertInstanceOf(InMemoryQueue::class, new MemoryQueueDriver()->create(new QueueConfiguration('memory')));
    }

    #[Test]
    public function it_creates_a_separate_queue_each_time(): void
    {
        $driver = new MemoryQueueDriver();
        $configuration = new QueueConfiguration('memory');

        $first = $driver->create($configuration);
        $second = $driver->create($configuration);
        $first->enqueue(new QueuedMessage('type', 'payload'));

        self::assertNotSame($first, $second);
        self::assertNull($second->reserve());
        self::assertNotNull($first->reserve());
    }
}
