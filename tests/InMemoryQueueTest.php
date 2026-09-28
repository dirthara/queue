<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use Dirthara\Queue\QueuedMessage;
use Dirthara\Queue\Contract\Delivery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Queue\Driver\Memory\InMemoryQueue;
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

    #[Test]
    public function it_delivers_a_new_message_as_its_first_attempt(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('type', 'payload'));

        self::assertSame(1, $queue->reserve()?->attempt);
    }

    #[Test]
    public function it_counts_each_release_as_another_attempt(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage('type', 'payload');
        $queue->enqueue($message);

        $queue->reserve()?->release();
        $queue->reserve()?->release();
        $delivery = $queue->reserve();

        self::assertSame(3, $delivery?->attempt);
        self::assertSame($message, $delivery?->message);
    }

    #[Test]
    public function it_counts_attempts_per_message(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('retried', 'one'));
        $queue->reserve()?->release();
        $queue->enqueue(new QueuedMessage('new', 'two'));

        $retried = $queue->reserve();
        $new = $queue->reserve();

        self::assertSame(['retried', 2], [$retried?->message->type, $retried?->attempt]);
        self::assertSame(['new', 1], [$new?->message->type, $new?->attempt]);
    }

    #[Test]
    public function it_starts_counting_again_when_the_same_message_is_enqueued_again(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage('type', 'payload');
        $queue->enqueue($message);
        $queue->reserve()?->release();
        $queue->reserve()?->acknowledge();

        $queue->enqueue($message);

        self::assertSame(1, $queue->reserve()?->attempt);
    }

    #[Test]
    public function it_has_no_failed_messages_until_one_is_failed(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('type', 'payload'));
        $queue->reserve()?->release();
        $queue->reserve()?->acknowledge();

        self::assertSame([], $queue->failed);
    }

    #[Test]
    public function it_keeps_a_failed_message_with_its_failure_instead_of_delivering_it_again(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage('failed', 'one');
        $queue->enqueue($message);
        $queue->enqueue(new QueuedMessage('waiting', 'two'));
        $failure = new RuntimeException('The handler failed.');

        $queue->reserve()?->fail($failure);

        self::assertCount(1, $queue->failed);
        self::assertSame($message, $queue->failed[0]->message);
        self::assertSame($failure, $queue->failed[0]->failure);
        self::assertSame('waiting', $queue->reserve()?->message->type);
        self::assertNull($queue->reserve());
    }

    #[Test]
    public function it_keeps_a_message_failed_without_a_failure(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage('type', 'payload');
        $queue->enqueue($message);

        $queue->reserve()?->fail();

        self::assertCount(1, $queue->failed);
        self::assertSame($message, $queue->failed[0]->message);
        self::assertNull($queue->failed[0]->failure);
        self::assertNull($queue->reserve());
    }

    #[Test]
    public function it_keeps_failed_messages_in_the_order_they_failed(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('first', 'one'));
        $queue->enqueue(new QueuedMessage('second', 'two'));

        $first = $queue->reserve();
        $second = $queue->reserve();
        $second?->fail();
        $first?->fail();

        self::assertSame(['second', 'first'], [$queue->failed[0]->message->type, $queue->failed[1]->message->type]);
    }

    #[Test]
    public function it_does_not_keep_a_failed_message_when_the_failure_is_refused(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('type', 'payload'));
        $delivery = $queue->reserve();
        self::assertNotNull($delivery);
        $delivery->acknowledge();

        try {
            $delivery->fail(new RuntimeException('The handler failed.'));
            self::fail('An acknowledged delivery was failed.');
        } catch (DeliveryAlreadySettledException) {
            self::assertSame([], $queue->failed);
        }
    }

    /**
     * @return iterable<string, array{callable(Delivery): void, callable(Delivery): void, string}>
     */
    public static function settlementsAfterAFailure(): iterable
    {
        $fail = static fn(Delivery $delivery): null => $delivery->fail();

        yield 'failed, then failed' => [$fail, $fail, 'failed'];
        yield 'failed, then acknowledged' => [
            $fail,
            static fn(Delivery $delivery): null => $delivery->acknowledge(),
            'failed',
        ];
        yield 'failed, then released' => [$fail, static fn(Delivery $delivery): null => $delivery->release(), 'failed'];
        yield 'acknowledged, then failed' => [
            static fn(Delivery $delivery): null => $delivery->acknowledge(),
            $fail,
            'acknowledged',
        ];
        yield 'released, then failed' => [
            static fn(Delivery $delivery): null => $delivery->release(),
            $fail,
            'released',
        ];
    }

    /**
     * @param callable(Delivery): void $first
     * @param callable(Delivery): void $second
     */
    #[Test]
    #[DataProvider('settlementsAfterAFailure')]
    public function it_settles_a_delivery_only_once_when_one_settlement_is_a_failure(
        callable $first,
        callable $second,
        string $settled,
    ): void {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('type', 'payload'));
        $delivery = $queue->reserve();
        self::assertNotNull($delivery);
        $first($delivery);

        try {
            $second($delivery);
            self::fail('A settled delivery was settled again.');
        } catch (DeliveryAlreadySettledException $exception) {
            self::assertSame(['message' => 'type', 'settled' => $settled], $exception->context);
        }
    }
}
