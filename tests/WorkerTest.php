<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests;

use RuntimeException;
use Dirthara\Queue\Worker;
use PHPUnit\Framework\TestCase;
use Dirthara\Queue\WorkerOutcome;
use Dirthara\Queue\Contract\Queue;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\ValueObject\Failure;
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
use Dirthara\Queue\Contract\MessageSerialiser;
use Dirthara\Queue\Retry\UnlimitedRetryPolicy;
use Dirthara\Queue\Driver\Memory\InMemoryQueue;
use Dirthara\Queue\MessageExecutionPolicyRegistry;
use Dirthara\Queue\Tests\Fixtures\GenerateInvoice;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmail;
use Dirthara\Queue\Serialiser\NativeMessageSerialiser;
use Dirthara\Queue\Tests\Fixtures\SingleDeliveryQueue;
use Dirthara\Queue\ValueObject\MessageExecutionPolicy;
use Dirthara\Queue\Tests\Fixtures\RecordingRetryPolicy;
use Dirthara\Queue\Tests\Fixtures\RecordingBackoffPolicy;
use Dirthara\Queue\Exception\MessageSerialisationException;
use Dirthara\Queue\Exception\MessageHandlerNotFoundException;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmailSerialiser;
use Dirthara\Queue\Tests\Fixtures\RecordingExecutionPolicyProvider;

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
    public function it_hands_the_deserialised_message_to_its_handler_and_acknowledges_it(): void
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
        self::assertSame([], $queue->failed());
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
    public function it_reports_a_message_it_cannot_deserialise_as_a_failure_without_handling_it(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage(SendWelcomeEmail::class, 'not a serialised value');
        $queue->enqueue($message);

        $handled = false;
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use (&$handled): void {
            $handled = true;
        });

        $result = self::worker($queue, $handlers, serialiser: new NativeMessageSerialiser())->runOnce();

        self::assertSame(WorkerOutcome::Failed, $result->outcome);
        self::assertInstanceOf(MessageSerialisationException::class, $result->failure);
        self::assertFalse($handled);
        self::assertSame($message, $queue->reserve()?->message);
    }

    #[Test]
    public function it_hands_a_natively_serialised_message_to_its_handler(): void
    {
        $queue = new InMemoryQueue();
        $serialiser = new NativeMessageSerialiser();
        $queue->enqueue($serialiser->serialise(new SendWelcomeEmail('ada@example.com')));

        $handled = [];
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use (&$handled): void {
            $handled[] = $message;
        });

        self::assertSame(
            WorkerOutcome::Handled,
            self::worker($queue, $handlers, serialiser: $serialiser)->runOnce()->outcome,
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
        self::assertCount(1, $queue->failed());
        self::assertEquals(Failure::fromThrowable($failure), $queue->failed()[0]->failure);
        self::assertSame([], $backoff->asked);
    }

    #[Test]
    public function it_fails_a_message_it_cannot_deserialise_when_the_retry_policy_declines(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'not a serialised value'));

        $result = self::worker(
            $queue,
            new MessageHandlerRegistry(),
            new NeverRetryPolicy(),
            serialiser: new NativeMessageSerialiser(),
        )
            ->runOnce();

        self::assertNull($queue->reserve());
        self::assertCount(1, $queue->failed());
        self::assertSame(MessageSerialisationException::class, $queue->failed()[0]->failure?->type);
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
        self::assertCount(1, $queue->failed());
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
        self::assertSame([], $queue->failed());
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
        $serialiser = new NativeMessageSerialiser();

        $handled = [];
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use (&$handled): void {
            $handled[] = $message;
        });

        $worker = self::worker($queue, $handlers, serialiser: $serialiser);

        new QueuedMessagePublisher($queue, $serialiser)->publishAfter(
            new SendWelcomeEmail('ada@example.com'),
            Duration::hours(1),
        );

        self::assertSame(WorkerOutcome::Idle, $worker->runOnce()->outcome);

        $clock->advance('+1 hour');

        self::assertSame(WorkerOutcome::Handled, $worker->runOnce()->outcome);
        self::assertEquals([new SendWelcomeEmail('ada@example.com')], $handled);
    }

    #[Test]
    public function it_settles_a_payload_it_cannot_deserialise_with_the_default_execution_policy(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'not a serialised value'));

        $defaultRetry = new RecordingRetryPolicy(retry: false);
        $overrideRetry = new RecordingRetryPolicy(retry: true);
        $policies = new RecordingExecutionPolicyProvider(
            new MessageExecutionPolicy($defaultRetry, new NoBackoffPolicy()),
            new MessageExecutionPolicy($overrideRetry, new NoBackoffPolicy()),
        );

        $result = new Worker($queue, new NativeMessageSerialiser(), new MessageHandlerRegistry(), $policies)->runOnce();

        self::assertSame(WorkerOutcome::Failed, $result->outcome);
        self::assertSame([], $policies->asked);
        self::assertCount(1, $defaultRetry->asked);
        self::assertSame([], $overrideRetry->asked);
        self::assertCount(1, $queue->failed());
    }

    #[Test]
    public function it_resolves_the_execution_policy_for_the_deserialised_message(): void
    {
        $queue = new InMemoryQueue();
        $serialiser = new NativeMessageSerialiser();
        $queue->enqueue($serialiser->serialise(new SendWelcomeEmail('ada@example.com')));

        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message): void {});
        $policy = new MessageExecutionPolicy(new UnlimitedRetryPolicy(), new NoBackoffPolicy());
        $policies = new RecordingExecutionPolicyProvider($policy, $policy);

        new Worker($queue, $serialiser, $handlers, $policies)->runOnce();

        self::assertEquals([new SendWelcomeEmail('ada@example.com')], $policies->asked);
    }

    #[Test]
    public function it_settles_a_message_without_a_handler_with_its_own_execution_policy(): void
    {
        $queue = new InMemoryQueue();
        $serialiser = new NativeMessageSerialiser();
        $queue->enqueue($serialiser->serialise(new SendWelcomeEmail('ada@example.com')));

        $defaultRetry = new RecordingRetryPolicy(retry: true);
        $welcomeRetry = new RecordingRetryPolicy(retry: false);
        $policies = new MessageExecutionPolicyRegistry(
            new MessageExecutionPolicy($defaultRetry, new NoBackoffPolicy()),
        );
        $policies->register(SendWelcomeEmail::class, new MessageExecutionPolicy($welcomeRetry, new NoBackoffPolicy()));

        $result = new Worker($queue, $serialiser, new MessageHandlerRegistry(), $policies)->runOnce();

        self::assertInstanceOf(MessageHandlerNotFoundException::class, $result->failure);
        self::assertCount(1, $welcomeRetry->asked);
        self::assertSame([], $defaultRetry->asked);
        self::assertCount(1, $queue->failed());
    }

    #[Test]
    public function it_asks_the_retry_policy_of_the_failed_message_type(): void
    {
        $queue = new InMemoryQueue();
        $serialiser = new NativeMessageSerialiser();
        $queue->enqueue($serialiser->serialise(new SendWelcomeEmail('ada@example.com')));
        $failure = new RuntimeException('The mail server is unavailable.');

        $defaultRetry = new RecordingRetryPolicy(retry: true);
        $welcomeRetry = new RecordingRetryPolicy(retry: true);
        $policies = new MessageExecutionPolicyRegistry(
            new MessageExecutionPolicy($defaultRetry, new NoBackoffPolicy()),
        );
        $policies->register(SendWelcomeEmail::class, new MessageExecutionPolicy($welcomeRetry, new NoBackoffPolicy()));

        new Worker($queue, $serialiser, self::failingHandlers($failure), $policies)->runOnce();

        self::assertCount(1, $welcomeRetry->asked);
        self::assertSame($failure, $welcomeRetry->asked[0]['failure']);
        self::assertSame(1, $welcomeRetry->asked[0]['delivery']->attempt);
        self::assertSame([], $defaultRetry->asked);
    }

    #[Test]
    public function it_delays_a_retry_by_the_backoff_of_the_failed_message_type(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));
        $serialiser = new NativeMessageSerialiser();
        $queue->enqueue($serialiser->serialise(new SendWelcomeEmail('ada@example.com')));

        $defaultBackoff = new RecordingBackoffPolicy(Duration::seconds(1));
        $welcomeBackoff = new RecordingBackoffPolicy(Duration::minutes(5));
        $policies = new MessageExecutionPolicyRegistry(
            new MessageExecutionPolicy(new UnlimitedRetryPolicy(), $defaultBackoff),
        );
        $policies->register(
            SendWelcomeEmail::class,
            new MessageExecutionPolicy(new UnlimitedRetryPolicy(), $welcomeBackoff),
        );

        $worker = new Worker(
            $queue,
            $serialiser,
            self::failingHandlers(new RuntimeException('The mail server is unavailable.')),
            $policies,
        );

        self::assertSame(WorkerOutcome::Failed, $worker->runOnce()->outcome);
        self::assertCount(1, $welcomeBackoff->asked);
        self::assertSame([], $defaultBackoff->asked);

        $clock->advance('+4 minutes +59 seconds');
        self::assertSame(WorkerOutcome::Idle, $worker->runOnce()->outcome);

        $clock->advance('+1 second');
        self::assertSame(WorkerOutcome::Failed, $worker->runOnce()->outcome);
    }

    #[Test]
    public function it_attempts_each_message_type_as_often_as_its_own_policy_allows(): void
    {
        $queue = new InMemoryQueue();
        $serialiser = new NativeMessageSerialiser();
        $queue->enqueue($serialiser->serialise(new SendWelcomeEmail('ada@example.com')));
        $queue->enqueue($serialiser->serialise(new GenerateInvoice('INV-1')));

        $attempts = [SendWelcomeEmail::class => [], GenerateInvoice::class => []];
        $handler = static function (object $message) use (&$attempts): void {
            $attempts[$message::class][] = $message;

            throw new RuntimeException('The external service is unavailable.');
        };
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, $handler);
        $handlers->register(GenerateInvoice::class, $handler);

        $policies = new MessageExecutionPolicyRegistry(
            new MessageExecutionPolicy(new AttemptsRetryPolicy(3), new NoBackoffPolicy()),
        );
        $policies->register(
            SendWelcomeEmail::class,
            new MessageExecutionPolicy(new AttemptsRetryPolicy(5), new NoBackoffPolicy()),
        );

        $worker = new Worker($queue, $serialiser, $handlers, $policies);

        do {
            $outcome = $worker->runOnce()->outcome;
        } while ($outcome !== WorkerOutcome::Idle);

        self::assertCount(5, $attempts[SendWelcomeEmail::class]);
        self::assertCount(3, $attempts[GenerateInvoice::class]);

        $failed = [];

        foreach ($queue->failed() as $message) {
            $failed[$message->message->type] = $message->attempt;
        }

        self::assertSame([GenerateInvoice::class => 3, SendWelcomeEmail::class => 5], $failed);
    }

    #[Test]
    public function it_fails_a_message_type_that_is_never_retried_straight_away(): void
    {
        $queue = new InMemoryQueue();
        $serialiser = new NativeMessageSerialiser();
        $queue->enqueue($serialiser->serialise(new SendWelcomeEmail('ada@example.com')));

        $policies = new MessageExecutionPolicyRegistry(
            new MessageExecutionPolicy(new UnlimitedRetryPolicy(), new NoBackoffPolicy()),
        );
        $policies->register(
            SendWelcomeEmail::class,
            new MessageExecutionPolicy(new NeverRetryPolicy(), new FixedBackoffPolicy(Duration::minutes(1))),
        );

        $worker = new Worker(
            $queue,
            $serialiser,
            self::failingHandlers(new RuntimeException('The payment was declined.')),
            $policies,
        );

        self::assertSame(WorkerOutcome::Failed, $worker->runOnce()->outcome);
        self::assertSame(WorkerOutcome::Idle, $worker->runOnce()->outcome);
        self::assertCount(1, $queue->failed());
        self::assertSame(1, $queue->failed()[0]->attempt);
    }

    #[Test]
    public function it_does_not_consult_any_retry_or_backoff_policy_when_the_handler_succeeds(): void
    {
        $queue = new InMemoryQueue();
        $serialiser = new NativeMessageSerialiser();
        $queue->enqueue($serialiser->serialise(new SendWelcomeEmail('ada@example.com')));

        $defaultRetry = new RecordingRetryPolicy(retry: true);
        $defaultBackoff = new RecordingBackoffPolicy(Duration::seconds(1));
        $welcomeRetry = new RecordingRetryPolicy(retry: true);
        $welcomeBackoff = new RecordingBackoffPolicy(Duration::seconds(1));
        $policies = new MessageExecutionPolicyRegistry(new MessageExecutionPolicy($defaultRetry, $defaultBackoff));
        $policies->register(SendWelcomeEmail::class, new MessageExecutionPolicy($welcomeRetry, $welcomeBackoff));

        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message): void {});

        self::assertSame(
            WorkerOutcome::Handled,
            new Worker($queue, $serialiser, $handlers, $policies)->runOnce()->outcome,
        );
        self::assertSame([], $defaultRetry->asked);
        self::assertSame([], $defaultBackoff->asked);
        self::assertSame([], $welcomeRetry->asked);
        self::assertSame([], $welcomeBackoff->asked);
    }

    #[Test]
    public function it_describes_the_handled_delivery_by_its_queued_message_and_attempt(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com');
        $queue->enqueue($message);
        $queue->reserve()?->release();

        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message): void {});

        $result = self::worker($queue, $handlers)->runOnce();

        self::assertSame(WorkerOutcome::Handled, $result->outcome);
        self::assertSame($message, $result->message);
        self::assertSame(2, $result->attempt);
        self::assertNull($result->failure);
    }

    #[Test]
    public function it_describes_the_failed_delivery_by_its_queued_message_attempt_and_original_failure(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com');
        $queue->enqueue($message);
        $failure = new RuntimeException('The mail server is unavailable.');
        $worker = self::worker($queue, self::failingHandlers($failure));

        $first = $worker->runOnce();
        $second = $worker->runOnce();

        self::assertSame([$message, 1, $failure], [$first->message, $first->attempt, $first->failure]);
        self::assertSame([$message, 2, $failure], [$second->message, $second->attempt, $second->failure]);
    }

    #[Test]
    public function it_describes_a_payload_it_cannot_deserialise_by_its_queued_message(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage(SendWelcomeEmail::class, 'not a serialised value');
        $queue->enqueue($message);

        $result = self::worker($queue, new MessageHandlerRegistry(), serialiser: new NativeMessageSerialiser())
            ->runOnce();

        self::assertSame(WorkerOutcome::Failed, $result->outcome);
        self::assertSame($message, $result->message);
        self::assertSame(1, $result->attempt);
    }

    #[Test]
    public function it_lets_a_failure_to_acknowledge_a_handled_delivery_escape(): void
    {
        $settlementFailure = new RuntimeException('The queue is unreachable.');
        $queue = new SingleDeliveryQueue(
            new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com'),
            $settlementFailure,
        );

        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message): void {});

        try {
            self::worker($queue, $handlers)->runOnce();
            self::fail('The settlement failure did not escape.');
        } catch (RuntimeException $exception) {
            self::assertSame($settlementFailure, $exception);
        }
    }

    #[Test]
    public function it_lets_a_failure_to_release_a_failed_delivery_escape(): void
    {
        $settlementFailure = new RuntimeException('The queue is unreachable.');
        $queue = new SingleDeliveryQueue(
            new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com'),
            $settlementFailure,
        );

        try {
            self::worker($queue, self::failingHandlers(new RuntimeException('The handler failed.')))->runOnce();
            self::fail('The settlement failure did not escape.');
        } catch (RuntimeException $exception) {
            self::assertSame($settlementFailure, $exception);
        }
    }

    #[Test]
    public function it_lets_a_failure_to_fail_a_delivery_escape(): void
    {
        $settlementFailure = new RuntimeException('The queue is unreachable.');
        $queue = new SingleDeliveryQueue(
            new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com'),
            $settlementFailure,
        );

        try {
            self::worker(
                $queue,
                self::failingHandlers(new RuntimeException('The handler failed.')),
                new NeverRetryPolicy(),
            )
                ->runOnce();
            self::fail('The settlement failure did not escape.');
        } catch (RuntimeException $exception) {
            self::assertSame($settlementFailure, $exception);
        }
    }

    private static function worker(
        Queue $queue,
        MessageHandlerRegistry $handlers,
        RetryPolicy $retryPolicy = new UnlimitedRetryPolicy(),
        BackoffPolicy $backoffPolicy = new NoBackoffPolicy(),
        MessageSerialiser $serialiser = new SendWelcomeEmailSerialiser(),
    ): Worker {
        return new Worker(
            $queue,
            $serialiser,
            $handlers,
            new MessageExecutionPolicyRegistry(new MessageExecutionPolicy($retryPolicy, $backoffPolicy)),
        );
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
