<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests;

use PHPUnit\Framework\TestCase;
use Dirthara\Queue\InMemoryQueue;
use Dirthara\Queue\QueuedMessage;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Exception\DeliveryAlreadySettledException;

final class InMemoryQueueTest extends TestCase
{
    #[Test]
    public function it_reserves_nothing_when_empty(): void
    {
        self::assertNull(new InMemoryQueue()->reserve());
    }

    #[Test]
    public function it_reserves_messages_in_the_order_they_were_enqueued(): void
    {
        $queue = new InMemoryQueue();
        $first = new QueuedMessage('first', 'one');
        $second = new QueuedMessage('second', 'two');

        $queue->enqueue($first);
        $queue->enqueue($second);

        self::assertSame($first, $queue->reserve()?->message);
        self::assertSame($second, $queue->reserve()?->message);
        self::assertNull($queue->reserve());
    }

    #[Test]
    public function it_does_not_reserve_a_message_twice_while_it_is_reserved(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('type', 'payload'));

        self::assertNotNull($queue->reserve());
        self::assertNull($queue->reserve());
    }

    #[Test]
    public function it_forgets_an_acknowledged_message(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('type', 'payload'));

        $queue->reserve()?->acknowledge();

        self::assertNull($queue->reserve());
    }

    #[Test]
    public function it_reserves_a_released_message_again_after_what_was_already_waiting(): void
    {
        $queue = new InMemoryQueue();
        $released = new QueuedMessage('released', 'one');
        $waiting = new QueuedMessage('waiting', 'two');

        $queue->enqueue($released);
        $queue->enqueue($waiting);

        $queue->reserve()?->release();

        self::assertSame($waiting, $queue->reserve()?->message);
        self::assertSame($released, $queue->reserve()?->message);
        self::assertNull($queue->reserve());
    }

    #[Test]
    public function it_refuses_to_release_a_released_message_again(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('type', 'payload'));

        $delivery = $queue->reserve();
        self::assertNotNull($delivery);
        $delivery->release();

        try {
            $delivery->release();
            self::fail('A released delivery was released again.');
        } catch (DeliveryAlreadySettledException $exception) {
            self::assertSame(['message' => 'type', 'settled' => 'released'], $exception->context);
        }

        self::assertNotNull($queue->reserve());
        self::assertNull($queue->reserve());
    }

    #[Test]
    public function it_refuses_to_acknowledge_a_released_message(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('type', 'payload'));

        $delivery = $queue->reserve();
        self::assertNotNull($delivery);
        $delivery->release();

        try {
            $delivery->acknowledge();
            self::fail('A released delivery was acknowledged.');
        } catch (DeliveryAlreadySettledException $exception) {
            self::assertSame(['message' => 'type', 'settled' => 'released'], $exception->context);
        }

        self::assertNotNull($queue->reserve());
    }

    #[Test]
    public function it_refuses_to_release_an_acknowledged_message(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('type', 'payload'));

        $delivery = $queue->reserve();
        self::assertNotNull($delivery);
        $delivery->acknowledge();

        try {
            $delivery->release();
            self::fail('An acknowledged delivery was released.');
        } catch (DeliveryAlreadySettledException $exception) {
            self::assertSame(['message' => 'type', 'settled' => 'acknowledged'], $exception->context);
        }

        self::assertNull($queue->reserve());
    }

    #[Test]
    public function it_refuses_to_acknowledge_an_acknowledged_message_again(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('type', 'payload'));

        $delivery = $queue->reserve();
        self::assertNotNull($delivery);
        $delivery->acknowledge();

        $this->expectException(DeliveryAlreadySettledException::class);

        $delivery->acknowledge();
    }
}
