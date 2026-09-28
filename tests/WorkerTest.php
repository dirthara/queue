<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests;

use RuntimeException;
use Dirthara\Queue\Worker;
use PHPUnit\Framework\TestCase;
use Dirthara\Queue\QueuedMessage;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\MessageHandlerRegistry;
use Dirthara\Queue\Retry\NeverRetryPolicy;
use Dirthara\Queue\Backoff\NoBackoffPolicy;
use Dirthara\Queue\Tests\Fixtures\TestClock;
use Dirthara\Queue\Retry\AttemptsRetryPolicy;
use Dirthara\Queue\Backoff\FixedBackoffPolicy;
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
    public function it_does_nothing_when_the_queue_is_empty(): void
    {
        $worker = new Worker(
            new InMemoryQueue(),
            new SendWelcomeEmailSerializer(),
            new MessageHandlerRegistry(),
            new UnlimitedRetryPolicy(),
            new NoBackoffPolicy(),
        );

        self::assertFalse($worker->runOnce());
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

        $worker = new Worker(
            $queue,
            new SendWelcomeEmailSerializer(),
            $handlers,
            new UnlimitedRetryPolicy(),
            new NoBackoffPolicy(),
        );

        self::assertTrue($worker->runOnce());
        self::assertEquals([new SendWelcomeEmail('ada@example.com')], $handled);
        self::assertNull($queue->reserve());
    }

    #[Test]
    public function it_releases_the_message_and_rethrows_when_the_handler_fails(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com');
        $queue->enqueue($message);

        $failure = new RuntimeException('The mail server is unavailable.');
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use ($failure): void {
            throw $failure;
        });

        $worker = new Worker(
            $queue,
            new SendWelcomeEmailSerializer(),
            $handlers,
            new UnlimitedRetryPolicy(),
            new NoBackoffPolicy(),
        );

        try {
            $worker->runOnce();
            self::fail('The handler failure was not rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame($message, $queue->reserve()?->message);
    }

    #[Test]
    public function it_releases_the_message_and_rethrows_when_no_handler_is_registered(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com');
        $queue->enqueue($message);

        $worker = new Worker(
            $queue,
            new SendWelcomeEmailSerializer(),
            new MessageHandlerRegistry(),
            new UnlimitedRetryPolicy(),
            new NoBackoffPolicy(),
        );

        try {
            $worker->runOnce();
            self::fail('A message without a handler was not rethrown.');
        } catch (MessageHandlerNotFoundException) {
            self::assertSame($message, $queue->reserve()?->message);
        }
    }

    #[Test]
    public function it_releases_the_message_and_rethrows_when_it_cannot_be_deserialized(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage(SendWelcomeEmail::class, 'not a serialized value');
        $queue->enqueue($message);

        $handled = false;
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use (&$handled): void {
            $handled = true;
        });

        $worker = new Worker(
            $queue,
            new NativeMessageSerializer(),
            $handlers,
            new UnlimitedRetryPolicy(),
            new NoBackoffPolicy(),
        );

        try {
            $worker->runOnce();
            self::fail('A payload that cannot be deserialized was not rethrown.');
        } catch (MessageSerializationException) {
            self::assertFalse($handled);
            self::assertSame($message, $queue->reserve()?->message);
        }
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

        self::assertTrue(
            new Worker($queue, $serializer, $handlers, new UnlimitedRetryPolicy(), new NoBackoffPolicy())->runOnce(),
        );
        self::assertEquals([new SendWelcomeEmail('ada@example.com')], $handled);
        self::assertNull($queue->reserve());
    }

    #[Test]
    public function it_does_not_consult_the_retry_policy_when_the_handler_succeeds(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com'));

        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message): void {});
        $policy = new RecordingRetryPolicy(retry: true);

        self::assertTrue(
            new Worker($queue, new SendWelcomeEmailSerializer(), $handlers, $policy, new NoBackoffPolicy())->runOnce(),
        );
        self::assertSame([], $policy->asked);
    }

    #[Test]
    public function it_asks_the_retry_policy_about_the_failed_delivery_and_its_failure(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com');
        $queue->enqueue($message);

        $failure = new RuntimeException('The mail server is unavailable.');
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use ($failure): void {
            throw $failure;
        });
        $policy = new RecordingRetryPolicy(retry: true);

        try {
            new Worker($queue, new SendWelcomeEmailSerializer(), $handlers, $policy, new NoBackoffPolicy())->runOnce();
            self::fail('The handler failure was not rethrown.');
        } catch (RuntimeException) {
            self::assertCount(1, $policy->asked);
            self::assertSame($message, $policy->asked[0]['delivery']->message);
            self::assertSame(1, $policy->asked[0]['delivery']->attempt);
            self::assertSame($failure, $policy->asked[0]['failure']);
        }
    }

    #[Test]
    public function it_fails_the_message_and_rethrows_when_the_retry_policy_declines(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com'));

        $failure = new RuntimeException('The mail server is unavailable.');
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use ($failure): void {
            throw $failure;
        });

        try {
            new Worker(
                $queue,
                new SendWelcomeEmailSerializer(),
                $handlers,
                new NeverRetryPolicy(),
                new NoBackoffPolicy(),
            )->runOnce();
            self::fail('The handler failure was not rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
            self::assertNull($queue->reserve());
            self::assertCount(1, $queue->failed);
            self::assertSame($failure, $queue->failed[0]->failure);
        }
    }

    #[Test]
    public function it_fails_a_message_it_cannot_deserialize_when_the_retry_policy_declines(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'not a serialized value'));

        $worker = new Worker(
            $queue,
            new NativeMessageSerializer(),
            new MessageHandlerRegistry(),
            new NeverRetryPolicy(),
            new NoBackoffPolicy(),
        );

        try {
            $worker->runOnce();
            self::fail('A payload that cannot be deserialized was not rethrown.');
        } catch (MessageSerializationException $exception) {
            self::assertNull($queue->reserve());
            self::assertCount(1, $queue->failed);
            self::assertSame($exception, $queue->failed[0]->failure);
        }
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

        $worker = new Worker(
            $queue,
            new SendWelcomeEmailSerializer(),
            $handlers,
            new AttemptsRetryPolicy(3),
            new NoBackoffPolicy(),
        );

        for ($run = 0; $run < 3; $run++) {
            try {
                $worker->runOnce();
                self::fail('The handler failure was not rethrown.');
            } catch (RuntimeException) {
                self::assertCount($run + 1, $attempts);
            }
        }

        self::assertFalse($worker->runOnce());
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

        $worker = new Worker(
            $queue,
            new SendWelcomeEmailSerializer(),
            $handlers,
            new AttemptsRetryPolicy(3),
            new NoBackoffPolicy(),
        );

        try {
            $worker->runOnce();
            self::fail('The handler failure was not rethrown.');
        } catch (RuntimeException) {
            self::assertTrue($worker->runOnce());
        }

        self::assertCount(2, $attempts);
        self::assertFalse($worker->runOnce());
        self::assertSame([], $queue->failed);
    }

    #[Test]
    public function it_asks_the_backoff_policy_how_long_to_delay_a_retry(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com');
        $queue->enqueue($message);

        $failure = new RuntimeException('The mail server is unavailable.');
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use ($failure): void {
            throw $failure;
        });
        $backoff = new RecordingBackoffPolicy(Duration::milliseconds(0));

        try {
            new Worker(
                $queue,
                new SendWelcomeEmailSerializer(),
                $handlers,
                new UnlimitedRetryPolicy(),
                $backoff,
            )->runOnce();
            self::fail('The handler failure was not rethrown.');
        } catch (RuntimeException) {
            self::assertCount(1, $backoff->asked);
            self::assertSame($message, $backoff->asked[0]['delivery']->message);
            self::assertSame(1, $backoff->asked[0]['delivery']->attempt);
            self::assertSame($failure, $backoff->asked[0]['failure']);
        }
    }

    #[Test]
    public function it_does_not_ask_the_backoff_policy_when_the_message_is_not_retried(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com'));

        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message): void {
            throw new RuntimeException('The mail server is unavailable.');
        });
        $backoff = new RecordingBackoffPolicy(Duration::seconds(5));

        try {
            new Worker(
                $queue,
                new SendWelcomeEmailSerializer(),
                $handlers,
                new NeverRetryPolicy(),
                $backoff,
            )->runOnce();
            self::fail('The handler failure was not rethrown.');
        } catch (RuntimeException) {
            self::assertSame([], $backoff->asked);
        }
    }

    #[Test]
    public function it_does_not_ask_the_backoff_policy_when_the_handler_succeeds(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com'));

        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message): void {});
        $backoff = new RecordingBackoffPolicy(Duration::seconds(5));

        new Worker(
            $queue,
            new SendWelcomeEmailSerializer(),
            $handlers,
            new UnlimitedRetryPolicy(),
            $backoff,
        )->runOnce();

        self::assertSame([], $backoff->asked);
    }

    #[Test]
    public function it_delays_a_retried_message_by_the_backoff(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com'));

        $attempts = [];
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use (&$attempts): void {
            $attempts[] = $message;

            throw new RuntimeException('The mail server is unavailable.');
        });

        $worker = new Worker(
            $queue,
            new SendWelcomeEmailSerializer(),
            $handlers,
            new UnlimitedRetryPolicy(),
            new FixedBackoffPolicy(Duration::seconds(30)),
        );

        try {
            $worker->runOnce();
            self::fail('The handler failure was not rethrown.');
        } catch (RuntimeException) {
            self::assertCount(1, $attempts);
        }

        $clock->advance('+29 seconds');
        self::assertFalse($worker->runOnce());

        $clock->advance('+1 second');

        try {
            $worker->runOnce();
            self::fail('The handler failure was not rethrown.');
        } catch (RuntimeException) {
            self::assertCount(2, $attempts);
        }
    }
}
