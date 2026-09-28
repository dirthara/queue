<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use Dirthara\Queue\Contract\Delivery;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\ValueObject\Failure;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\Tests\Fixtures\TestClock;
use Dirthara\Queue\ValueObject\QueuedMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Queue\Driver\Memory\InMemoryQueue;
use Dirthara\Queue\Contract\FailedMessageRepository;
use Dirthara\Queue\Exception\FailedMessageNotFoundException;
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

        self::assertSame([], $queue->failed());
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

        self::assertCount(1, $queue->failed());
        self::assertSame($message, $queue->failed()[0]->message);
        self::assertEquals(Failure::fromThrowable($failure), $queue->failed()[0]->failure);
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

        self::assertCount(1, $queue->failed());
        self::assertSame($message, $queue->failed()[0]->message);
        self::assertNull($queue->failed()[0]->failure);
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

        self::assertSame(['second', 'first'], [$queue->failed()[0]->message->type, $queue->failed()[1]->message->type]);
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
            self::assertSame([], $queue->failed());
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

    #[Test]
    public function it_delivers_a_message_released_without_a_delay_again_straight_away(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));
        $queue->enqueue(new QueuedMessage('type', 'payload'));

        $queue->reserve()?->release();

        self::assertSame(2, $queue->reserve()?->attempt);
    }

    #[Test]
    public function it_delivers_a_message_released_with_a_zero_delay_again_straight_away(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));
        $queue->enqueue(new QueuedMessage('type', 'payload'));

        $queue->reserve()?->release(Duration::milliseconds(0));

        self::assertSame(2, $queue->reserve()?->attempt);
    }

    #[Test]
    public function it_holds_back_a_delayed_message_until_its_delay_has_passed(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));
        $queue->enqueue(new QueuedMessage('type', 'payload'));

        $queue->reserve()?->release(Duration::milliseconds(1500));

        self::assertNull($queue->reserve());

        $clock->advance('+1499 milliseconds');
        self::assertNull($queue->reserve());

        $clock->advance('+1 millisecond');
        self::assertSame(2, $queue->reserve()?->attempt);
    }

    #[Test]
    public function it_delivers_waiting_messages_while_a_delayed_one_is_held_back(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));
        $queue->enqueue(new QueuedMessage('delayed', 'one'));
        $queue->reserve()?->release(Duration::minutes(1));
        $queue->enqueue(new QueuedMessage('waiting', 'two'));

        self::assertSame('waiting', $queue->reserve()?->message->type);
        self::assertNull($queue->reserve());
    }

    #[Test]
    public function it_delivers_available_messages_in_the_order_they_were_queued(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));
        $queue->enqueue(new QueuedMessage('first', 'one'));
        $queue->enqueue(new QueuedMessage('second', 'two'));
        $queue->reserve()?->release(Duration::seconds(10));
        $queue->reserve()?->release(Duration::seconds(5));

        $clock->advance('+10 seconds');

        self::assertSame('first', $queue->reserve()?->message->type);
        self::assertSame('second', $queue->reserve()?->message->type);
    }

    #[Test]
    public function it_measures_a_delay_from_when_the_message_is_released(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));
        $queue->enqueue(new QueuedMessage('type', 'payload'));
        $delivery = $queue->reserve();

        $clock->advance('+1 hour');
        $delivery?->release(Duration::seconds(30));

        self::assertNull($queue->reserve());

        $clock->advance('+30 seconds');
        self::assertNotNull($queue->reserve());
    }

    #[Test]
    public function it_holds_back_a_message_for_a_delay_of_years(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));
        $queue->enqueue(new QueuedMessage('type', 'payload'));

        $queue->reserve()?->release(Duration::hours(24 * 365 * 1000));

        $clock->advance('+999 years');
        self::assertNull($queue->reserve());
    }

    #[Test]
    public function it_uses_the_current_time_without_a_clock(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('type', 'payload'));

        $queue->reserve()?->release(Duration::hours(1));

        self::assertNull($queue->reserve());
    }

    #[Test]
    public function it_holds_back_a_message_enqueued_with_a_delay_until_the_delay_has_passed(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));
        $message = new QueuedMessage('type', 'payload');

        $queue->enqueue($message, Duration::seconds(30));

        self::assertNull($queue->reserve());

        $clock->advance('+29 seconds +999 milliseconds');
        self::assertNull($queue->reserve());

        $clock->advance('+1 millisecond');
        $delivery = $queue->reserve();

        self::assertSame($message, $delivery?->message);
        self::assertSame(1, $delivery?->attempt);
    }

    #[Test]
    public function it_makes_a_message_enqueued_with_a_zero_delay_available_straight_away(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));

        $queue->enqueue(new QueuedMessage('type', 'payload'), Duration::milliseconds(0));

        self::assertNotNull($queue->reserve());
    }

    #[Test]
    public function it_delivers_messages_enqueued_with_different_delays_as_each_becomes_available(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));
        $queue->enqueue(new QueuedMessage('later', 'one'), Duration::minutes(2));
        $queue->enqueue(new QueuedMessage('sooner', 'two'), Duration::minutes(1));

        $clock->advance('+1 minute');
        self::assertSame('sooner', $queue->reserve()?->message->type);
        self::assertNull($queue->reserve());

        $clock->advance('+1 minute');
        self::assertSame('later', $queue->reserve()?->message->type);
    }

    #[Test]
    public function it_measures_a_retry_delay_from_the_release_rather_than_the_original_delay(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));
        $queue->enqueue(new QueuedMessage('type', 'payload'), Duration::minutes(10));

        $clock->advance('+10 minutes');
        $queue->reserve()?->release(Duration::seconds(5));

        self::assertNull($queue->reserve());

        $clock->advance('+5 seconds');
        self::assertSame(2, $queue->reserve()?->attempt);
    }

    #[Test]
    public function it_is_a_failed_message_repository(): void
    {
        self::assertInstanceOf(FailedMessageRepository::class, new InMemoryQueue());
    }

    #[Test]
    public function it_records_the_attempt_and_a_summary_of_the_failure(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('type', 'payload'));
        $queue->reserve()?->release();

        $queue->reserve()?->fail(new RuntimeException('The mail server is unavailable.', 503));

        $failed = $queue->failed()[0];

        self::assertSame(2, $failed->attempt);
        self::assertEquals(
            new Failure(RuntimeException::class, 'The mail server is unavailable.', 503),
            $failed->failure,
        );
    }

    #[Test]
    public function it_gives_each_failed_message_its_own_id(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('type', 'payload'));
        $queue->enqueue(new QueuedMessage('type', 'payload'));
        $queue->reserve()?->fail();
        $queue->reserve()?->fail();

        [$first, $second] = $queue->failed();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first->id);
        self::assertNotSame($first->id, $second->id);
    }

    #[Test]
    public function it_finds_a_failed_message_by_its_id(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('first', 'one'));
        $queue->enqueue(new QueuedMessage('second', 'two'));
        $queue->reserve()?->fail();
        $queue->reserve()?->fail();

        [$first, $second] = $queue->failed();

        self::assertSame($first, $queue->findFailed($first->id));
        self::assertSame($second, $queue->findFailed($second->id));
    }

    #[Test]
    public function it_finds_nothing_for_an_unknown_id(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('type', 'payload'));
        $queue->reserve()?->fail();

        self::assertNull($queue->findFailed('unknown'));
    }

    #[Test]
    public function it_retries_a_failed_message_as_a_new_first_attempt(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage('type', 'payload');
        $queue->enqueue($message);
        $queue->reserve()?->release();
        $queue->reserve()?->fail();
        $id = $queue->failed()[0]->id;

        $queue->retry($id);

        $delivery = $queue->reserve();

        self::assertSame($message, $delivery?->message);
        self::assertSame(1, $delivery?->attempt);
        self::assertSame([], $queue->failed());
        self::assertNull($queue->findFailed($id));
    }

    #[Test]
    public function it_retries_a_failed_message_straight_away_after_what_is_already_waiting(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));
        $queue->enqueue(new QueuedMessage('failed', 'one'), Duration::hours(1));
        $clock->advance('+1 hour');
        $queue->reserve()?->fail();
        $queue->enqueue(new QueuedMessage('waiting', 'two'));

        $queue->retry($queue->failed()[0]->id);

        self::assertSame('waiting', $queue->reserve()?->message->type);
        self::assertSame('failed', $queue->reserve()?->message->type);
    }

    #[Test]
    public function it_records_a_retried_message_that_fails_again_under_a_new_id(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('type', 'payload'));
        $queue->reserve()?->fail();
        $id = $queue->failed()[0]->id;

        $queue->retry($id);
        $queue->reserve()?->fail();

        self::assertCount(1, $queue->failed());
        self::assertNotSame($id, $queue->failed()[0]->id);
        self::assertSame(1, $queue->failed()[0]->attempt);
    }

    #[Test]
    public function it_forgets_a_failed_message_without_delivering_it_again(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('forgotten', 'one'));
        $queue->enqueue(new QueuedMessage('kept', 'two'));
        $queue->reserve()?->fail();
        $queue->reserve()?->fail();
        [$forgotten, $kept] = $queue->failed();

        $queue->forget($forgotten->id);

        self::assertSame([$kept], $queue->failed());
        self::assertNull($queue->findFailed($forgotten->id));
        self::assertNull($queue->reserve());
    }

    /**
     * @return iterable<string, array{callable(InMemoryQueue, string): void}>
     */
    public static function failedMessageOperations(): iterable
    {
        yield 'retrying' => [static fn(InMemoryQueue $queue, string $id): null => $queue->retry($id)];
        yield 'forgetting' => [static fn(InMemoryQueue $queue, string $id): null => $queue->forget($id)];
    }

    /**
     * @param callable(InMemoryQueue, string): void $operation
     */
    #[Test]
    #[DataProvider('failedMessageOperations')]
    public function it_refuses_an_unknown_failed_message(callable $operation): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('type', 'payload'));
        $queue->reserve()?->fail();

        try {
            $unknown = 'unknown';
            $operation($queue, $unknown);
            self::fail('An unknown failed message was accepted.');
        } catch (FailedMessageNotFoundException $exception) {
            self::assertSame(['id' => 'unknown'], $exception->context);
            self::assertCount(1, $queue->failed());
            self::assertNull($queue->reserve());
        }
    }

    /**
     * @param callable(InMemoryQueue, string): void $operation
     */
    #[Test]
    #[DataProvider('failedMessageOperations')]
    public function it_refuses_a_failed_message_it_already_retried_or_forgot(callable $operation): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('type', 'payload'));
        $queue->reserve()?->fail();
        $id = $queue->failed()[0]->id;
        $operation($queue, $id);

        $this->expectException(FailedMessageNotFoundException::class);

        $operation($queue, $id);
    }
}
