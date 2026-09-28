<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests;

use RuntimeException;
use Dirthara\Queue\Worker;
use PHPUnit\Framework\TestCase;
use Dirthara\Queue\WorkerOutcome;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Contract\RetryPolicy;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\Contract\BackoffPolicy;
use Dirthara\Queue\MessageHandlerRegistry;
use Dirthara\Queue\QueuedMessagePublisher;
use Dirthara\Queue\Retry\NeverRetryPolicy;
use Dirthara\Queue\Backoff\NoBackoffPolicy;
use Dirthara\Queue\Tests\Fixtures\TestClock;
use Dirthara\Queue\Retry\AttemptsRetryPolicy;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Queue\Backoff\FixedBackoffPolicy;
use Dirthara\Queue\Contract\MessageSerializer;
use Dirthara\Queue\Retry\UnlimitedRetryPolicy;
use Dirthara\Queue\Driver\Memory\InMemoryQueue;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmail;
use Dirthara\Queue\Serializer\NativeMessageSerializer;
use Dirthara\Queue\Tests\Fixtures\RecordingRetryPolicy;
use Dirthara\Queue\Tests\Fixtures\RecordingBackoffPolicy;
use Dirthara\Queue\Exception\MessageSerializationException;
use Dirthara\Queue\Exception\MessageHandlerNotFoundException;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmailSerializer;

use function count;

final class WorkerTest extends TestCase
{
    #[Test]
    public function it_is_idle_when_the_queue_is_empty(): void
    {
        $result = self::worker(new InMemoryQueue(), new MessageHandlerRegistry())->runOnce();

        self::assertSame(WorkerOutcome::Idle, $result->outcome);
        self::assertNull($result->failure);
    }

    #[Test]
    public function it_hands_the_deserialized_message_to_its_handler_and_acknowledges_it(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com'));

        $handled = [];
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use (&$handled): void {
            $handled[] = $message;
        });

        $result = self::worker($queue, $handlers)->runOnce();

        self::assertSame(WorkerOutcome::Handled, $result->outcome);
        self::assertNull($result->failure);
        self::assertEquals([new SendWelcomeEmail('ada@example.com')], $handled);
        self::assertNull($queue->reserve());
        self::assertSame([], $queue->failed);
    }

    #[Test]
    public function it_reports_a_handler_failure_and_releases_the_message_when_the_retry_policy_allows(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com');
        $queue->enqueue($message);
        $failure = new RuntimeException('The mail server is unavailable.');

        $result = self::worker($queue, self::failingHandlers($failure))->runOnce();

        self::assertSame(WorkerOutcome::Failed, $result->outcome);
        self::assertSame($failure, $result->failure);
        self::assertSame($message, $queue->reserve()?->message);
    }

    #[Test]
    public function it_reports_a_message_without_a_handler_as_a_failure(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com');
        $queue->enqueue($message);

        $result = self::worker($queue, new MessageHandlerRegistry())->runOnce();

        self::assertSame(WorkerOutcome::Failed, $result->outcome);
        self::assertInstanceOf(MessageHandlerNotFoundException::class, $result->failure);
        self::assertSame($message, $queue->reserve()?->message);
    }

    #[Test]
    public function it_reports_a_message_it_cannot_deserialize_as_a_failure_without_handling_it(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage(SendWelcomeEmail::class, 'not a serialized value');
        $queue->enqueue($message);

        $handled = false;
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use (&$handled): void {
            $handled = true;
        });

        $result = self::worker($queue, $handlers, serializer: new NativeMessageSerializer())->runOnce();

        self::assertSame(WorkerOutcome::Failed, $result->outcome);
        self::assertInstanceOf(MessageSerializationException::class, $result->failure);
        self::assertFalse($handled);
        self::assertSame($message, $queue->reserve()?->message);
    }

    #[Test]
    public function it_hands_a_natively_serialized_message_to_its_handler(): void
    {
        $queue = new InMemoryQueue();
        $serializer = new NativeMessageSerializer();
        $queue->enqueue($serializer->serialize(new SendWelcomeEmail('ada@example.com')));

        $handled = [];
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use (&$handled): void {
            $handled[] = $message;
        });

        self::assertSame(
            WorkerOutcome::Handled,
            self::worker($queue, $handlers, serializer: $serializer)->runOnce()->outcome,
        );
        self::assertEquals([new SendWelcomeEmail('ada@example.com')], $handled);
    }

    #[Test]
    public function it_does_not_consult_the_retry_or_backoff_policy_when_the_handler_succeeds(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com'));

        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message): void {});
        $retry = new RecordingRetryPolicy(retry: true);
        $backoff = new RecordingBackoffPolicy(Duration::seconds(5));

        self::worker($queue, $handlers, $retry, $backoff)->runOnce();

        self::assertSame([], $retry->asked);
        self::assertSame([], $backoff->asked);
    }

    #[Test]
    public function it_asks_the_retry_policy_about_the_failed_delivery_and_its_failure(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com');
        $queue->enqueue($message);
        $failure = new RuntimeException('The mail server is unavailable.');
        $retry = new RecordingRetryPolicy(retry: true);

        self::worker($queue, self::failingHandlers($failure), $retry)->runOnce();

        self::assertCount(1, $retry->asked);
        self::assertSame($message, $retry->asked[0]['delivery']->message);
        self::assertSame(1, $retry->asked[0]['delivery']->attempt);
        self::assertSame($failure, $retry->asked[0]['failure']);
    }

    #[Test]
    public function it_fails_the_message_when_the_retry_policy_declines(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com'));
        $failure = new RuntimeException('The mail server is unavailable.');
        $backoff = new RecordingBackoffPolicy(Duration::seconds(5));

        $result = self::worker($queue, self::failingHandlers($failure), new NeverRetryPolicy(), $backoff)->runOnce();

        self::assertSame(WorkerOutcome::Failed, $result->outcome);
        self::assertSame($failure, $result->failure);
        self::assertNull($queue->reserve());
        self::assertCount(1, $queue->failed);
        self::assertSame($failure, $queue->failed[0]->failure);
        self::assertSame([], $backoff->asked);
    }

    #[Test]
    public function it_fails_a_message_it_cannot_deserialize_when_the_retry_policy_declines(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'not a serialized value'));

        $result = self::worker(
            $queue,
            new MessageHandlerRegistry(),
            new NeverRetryPolicy(),
            serializer: new NativeMessageSerializer(),
        )
            ->runOnce();

        self::assertNull($queue->reserve());
        self::assertCount(1, $queue->failed);
        self::assertSame($result->failure, $queue->failed[0]->failure);
    }

    #[Test]
    public function it_attempts_a_failing_message_as_often_as_the_retry_policy_allows(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com'));

        $attempts = [];
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use (&$attempts): void {
            $attempts[] = $message;

            throw new RuntimeException('The mail server is unavailable.');
        });

        $worker = self::worker($queue, $handlers, new AttemptsRetryPolicy(3));

        self::assertSame(WorkerOutcome::Failed, $worker->runOnce()->outcome);
        self::assertSame(WorkerOutcome::Failed, $worker->runOnce()->outcome);
        self::assertSame(WorkerOutcome::Failed, $worker->runOnce()->outcome);
        self::assertSame(WorkerOutcome::Idle, $worker->runOnce()->outcome);
        self::assertCount(3, $attempts);
        self::assertCount(1, $queue->failed);
    }

    #[Test]
    public function it_handles_a_message_that_succeeds_on_a_retry(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com'));

        $attempts = [];
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use (&$attempts): void {
            $attempts[] = $message;

            if (count($attempts) === 1) {
                throw new RuntimeException('The mail server is unavailable.');
            }
        });

        $worker = self::worker($queue, $handlers, new AttemptsRetryPolicy(3));

        self::assertSame(WorkerOutcome::Failed, $worker->runOnce()->outcome);
        self::assertSame(WorkerOutcome::Handled, $worker->runOnce()->outcome);
        self::assertSame(WorkerOutcome::Idle, $worker->runOnce()->outcome);
        self::assertCount(2, $attempts);
        self::assertSame([], $queue->failed);
    }

    #[Test]
    public function it_asks_the_backoff_policy_how_long_to_delay_a_retry(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com');
        $queue->enqueue($message);
        $failure = new RuntimeException('The mail server is unavailable.');
        $backoff = new RecordingBackoffPolicy(Duration::milliseconds(0));

        self::worker($queue, self::failingHandlers($failure), new UnlimitedRetryPolicy(), $backoff)->runOnce();

        self::assertCount(1, $backoff->asked);
        self::assertSame($message, $backoff->asked[0]['delivery']->message);
        self::assertSame(1, $backoff->asked[0]['delivery']->attempt);
        self::assertSame($failure, $backoff->asked[0]['failure']);
    }

    #[Test]
    public function it_delays_a_retried_message_by_the_backoff(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com'));

        $worker = self::worker(
            $queue,
            self::failingHandlers(new RuntimeException('The mail server is unavailable.')),
            new UnlimitedRetryPolicy(),
            new FixedBackoffPolicy(Duration::seconds(30)),
        );

        self::assertSame(WorkerOutcome::Failed, $worker->runOnce()->outcome);

        $clock->advance('+29 seconds');
        self::assertSame(WorkerOutcome::Idle, $worker->runOnce()->outcome);

        $clock->advance('+1 second');
        self::assertSame(WorkerOutcome::Failed, $worker->runOnce()->outcome);
    }

    #[Test]
    public function it_handles_a_message_published_after_a_delay_once_the_delay_has_passed(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));
        $serializer = new NativeMessageSerializer();

        $handled = [];
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use (&$handled): void {
            $handled[] = $message;
        });

        $worker = self::worker($queue, $handlers, serializer: $serializer);

        new QueuedMessagePublisher($queue, $serializer)->publishAfter(
            new SendWelcomeEmail('ada@example.com'),
            Duration::hours(1),
        );

        self::assertSame(WorkerOutcome::Idle, $worker->runOnce()->outcome);

        $clock->advance('+1 hour');

        self::assertSame(WorkerOutcome::Handled, $worker->runOnce()->outcome);
        self::assertEquals([new SendWelcomeEmail('ada@example.com')], $handled);
    }

    private static function worker(
        InMemoryQueue $queue,
        MessageHandlerRegistry $handlers,
        RetryPolicy $retryPolicy = new UnlimitedRetryPolicy(),
        BackoffPolicy $backoffPolicy = new NoBackoffPolicy(),
        MessageSerializer $serializer = new SendWelcomeEmailSerializer(),
    ): Worker {
        return new Worker($queue, $serializer, $handlers, $retryPolicy, $backoffPolicy);
    }

    private static function failingHandlers(RuntimeException $failure): MessageHandlerRegistry
    {
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use ($failure): void {
            throw $failure;
        });

        return $handlers;
    }
}
