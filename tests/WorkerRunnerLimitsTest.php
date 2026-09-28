<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests;

use Closure;
use RuntimeException;
use Dirthara\Queue\Worker;
use PHPUnit\Framework\TestCase;
use Dirthara\Queue\WorkerRunner;
use Dirthara\Queue\WorkerOutcome;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Contract\RetryPolicy;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\MessageHandlerRegistry;
use Dirthara\Queue\Retry\NeverRetryPolicy;
use Dirthara\Queue\Backoff\NoBackoffPolicy;
use Dirthara\Queue\Tests\Fixtures\TestTimer;
use Dirthara\Queue\ValueObject\WorkerLimits;
use Dirthara\Queue\ValueObject\WorkerResult;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Queue\Retry\UnlimitedRetryPolicy;
use Dirthara\Queue\Driver\Memory\InMemoryQueue;
use Dirthara\Queue\MessageExecutionPolicyRegistry;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmail;
use Dirthara\Queue\ValueObject\MessageExecutionPolicy;
use Dirthara\Queue\Tests\Fixtures\RecordingWorkerObserver;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmailSerializer;

use function count;
use function hrtime;
use function array_map;

final class WorkerRunnerLimitsTest extends TestCase
{
    #[Test]
    public function it_keeps_running_without_limits_until_it_is_stopped(): void
    {
        $timer = new TestTimer();
        $queue = self::queue('first@example.com', 'second@example.com');
        $observer = new RecordingWorkerObserver();
        $sleeps = [];
        $runner = null;
        $runner = new WorkerRunner(
            self::worker($queue, self::handlers()),
            Duration::seconds(1),
            $observer,
            new WorkerLimits(),
            sleep: static function (Duration $duration) use (&$sleeps, &$runner, $timer): void {
                $sleeps[] = $duration;
                $timer->advance(Duration::hours(24));

                if (count($sleeps) === 3) {
                    $runner?->stop();
                }
            },
            nanoseconds: $timer->now(...),
        );

        $runner->run();

        self::assertSame(
            [
                WorkerOutcome::Handled,
                WorkerOutcome::Handled,
                WorkerOutcome::Idle,
                WorkerOutcome::Idle,
                WorkerOutcome::Idle,
            ],
            self::outcomes($observer),
        );
    }

    #[Test]
    public function it_stops_after_the_maximum_number_of_handled_messages_and_leaves_the_rest_queued(): void
    {
        $queue = self::queue('first@example.com', 'second@example.com');
        $observer = new RecordingWorkerObserver();
        $handled = [];

        $runner = new WorkerRunner(
            self::worker($queue, self::handlers($handled)),
            Duration::seconds(1),
            $observer,
            new WorkerLimits(maxMessages: 1),
            sleep: self::unexpectedSleep(),
        );

        $runner->run();

        self::assertEquals([new SendWelcomeEmail('first@example.com')], $handled);
        self::assertSame([WorkerOutcome::Handled], self::outcomes($observer));
        self::assertSame('second@example.com', $queue->reserve()?->message->payload);
    }

    #[Test]
    public function it_counts_failed_messages_towards_the_maximum(): void
    {
        $queue = self::queue('first@example.com', 'second@example.com', 'third@example.com');
        $observer = new RecordingWorkerObserver();

        $runner = new WorkerRunner(
            self::worker($queue, self::failingHandlers(), new NeverRetryPolicy()),
            Duration::seconds(1),
            $observer,
            new WorkerLimits(maxMessages: 2),
            sleep: self::unexpectedSleep(),
        );

        $runner->run();

        self::assertSame([WorkerOutcome::Failed, WorkerOutcome::Failed], self::outcomes($observer));
        self::assertCount(2, $queue->failed());
        self::assertSame('third@example.com', $queue->reserve()?->message->payload);
    }

    #[Test]
    public function it_counts_each_retried_attempt_towards_the_maximum(): void
    {
        $queue = self::queue('ada@example.com');
        $observer = new RecordingWorkerObserver();

        $runner = new WorkerRunner(
            self::worker($queue, self::failingHandlers(), new UnlimitedRetryPolicy()),
            Duration::seconds(1),
            $observer,
            new WorkerLimits(maxMessages: 3),
            sleep: self::unexpectedSleep(),
        );

        $runner->run();

        self::assertSame(
            [1, 2, 3],
            array_map(static fn(WorkerResult $result): ?int => $result->attempt, $observer->observed),
        );
        self::assertSame(4, $queue->reserve()?->attempt);
    }

    #[Test]
    public function it_does_not_count_idle_polls_towards_the_maximum(): void
    {
        $queue = new InMemoryQueue();
        $observer = new RecordingWorkerObserver();
        $sleeps = 0;

        $runner = new WorkerRunner(
            self::worker($queue, self::handlers()),
            Duration::seconds(1),
            $observer,
            new WorkerLimits(maxMessages: 1),
            sleep: static function (Duration $duration) use (&$sleeps, $queue): void {
                $sleeps++;

                if ($sleeps === 2) {
                    $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'late@example.com'));
                }
            },
        );

        $runner->run();

        self::assertSame([WorkerOutcome::Idle, WorkerOutcome::Idle, WorkerOutcome::Handled], self::outcomes($observer));
    }

    #[Test]
    public function it_stops_an_idle_worker_once_the_runtime_has_passed(): void
    {
        $timer = new TestTimer();
        $observer = new RecordingWorkerObserver();
        $sleeps = [];

        $runner = new WorkerRunner(
            self::worker(new InMemoryQueue(), self::handlers()),
            Duration::seconds(1),
            $observer,
            new WorkerLimits(maxRuntime: Duration::seconds(3)),
            sleep: self::advancingSleep($timer, $sleeps),
            nanoseconds: $timer->now(...),
        );

        $runner->run();

        self::assertSame([1000, 1000, 1000], $sleeps);
        self::assertSame([WorkerOutcome::Idle, WorkerOutcome::Idle, WorkerOutcome::Idle], self::outcomes($observer));
    }

    #[Test]
    public function it_sleeps_no_longer_than_the_remaining_runtime(): void
    {
        $timer = new TestTimer();
        $sleeps = [];

        $runner = new WorkerRunner(
            self::worker(new InMemoryQueue(), self::handlers()),
            Duration::seconds(5),
            limits: new WorkerLimits(maxRuntime: Duration::milliseconds(5200)),
            sleep: self::advancingSleep($timer, $sleeps),
            nanoseconds: $timer->now(...),
        );

        $runner->run();

        self::assertSame([5000, 200], $sleeps);
    }

    #[Test]
    public function it_measures_the_runtime_from_when_it_starts_running(): void
    {
        $timer = new TestTimer();
        $timer->advance(Duration::hours(10));
        $sleeps = [];

        $runner = new WorkerRunner(
            self::worker(new InMemoryQueue(), self::handlers()),
            Duration::seconds(1),
            limits: new WorkerLimits(maxRuntime: Duration::seconds(2)),
            sleep: self::advancingSleep($timer, $sleeps),
            nanoseconds: $timer->now(...),
        );

        $runner->run();

        self::assertSame([1000, 1000], $sleeps);
    }

    #[Test]
    public function it_stops_without_sleeping_when_the_runtime_ran_out_during_an_idle_poll(): void
    {
        $timer = new TestTimer();
        $observer = new RecordingWorkerObserver(static function (WorkerResult $result) use ($timer): void {
            $timer->advance(Duration::minutes(2));
        });

        $runner = new WorkerRunner(
            self::worker(new InMemoryQueue(), self::handlers()),
            Duration::seconds(1),
            $observer,
            new WorkerLimits(maxRuntime: Duration::minutes(1)),
            sleep: self::unexpectedSleep(),
            nanoseconds: $timer->now(...),
        );

        $runner->run();

        self::assertSame([WorkerOutcome::Idle], self::outcomes($observer));
    }

    #[Test]
    public function it_finishes_a_handled_message_that_outlasts_the_runtime_and_then_stops(): void
    {
        $timer = new TestTimer();
        $queue = self::queue('slow@example.com', 'next@example.com');
        $observer = new RecordingWorkerObserver();
        $events = [];

        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use ($timer, &$events): void {
            $events[] = 'started';
            $timer->advance(Duration::minutes(2));
            $events[] = 'finished';
        });

        $runner = new WorkerRunner(
            self::worker($queue, $handlers),
            Duration::seconds(1),
            $observer,
            new WorkerLimits(maxRuntime: Duration::minutes(1)),
            sleep: self::unexpectedSleep(),
            nanoseconds: $timer->now(...),
        );

        $runner->run();

        self::assertSame(['started', 'finished'], $events);
        self::assertSame([WorkerOutcome::Handled], self::outcomes($observer));
        self::assertSame('slow@example.com', $observer->observed[0]->message?->payload);
        self::assertSame('next@example.com', $queue->reserve()?->message->payload);
        self::assertNull($queue->reserve());
    }

    #[Test]
    public function it_settles_a_failed_message_that_outlasts_the_runtime_and_then_stops(): void
    {
        $timer = new TestTimer();
        $queue = self::queue('slow@example.com', 'next@example.com');
        $observer = new RecordingWorkerObserver();

        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use ($timer): void {
            $timer->advance(Duration::minutes(2));

            throw new RuntimeException('The mail server is unavailable.');
        });

        $runner = new WorkerRunner(
            self::worker($queue, $handlers, new UnlimitedRetryPolicy()),
            Duration::seconds(1),
            $observer,
            new WorkerLimits(maxRuntime: Duration::minutes(1)),
            sleep: self::unexpectedSleep(),
            nanoseconds: $timer->now(...),
        );

        $runner->run();

        self::assertSame([WorkerOutcome::Failed], self::outcomes($observer));
        self::assertSame('next@example.com', $queue->reserve()?->message->payload);

        $retried = $queue->reserve();

        self::assertSame('slow@example.com', $retried?->message->payload);
        self::assertSame(2, $retried?->attempt);
    }

    #[Test]
    public function it_stops_at_whichever_limit_is_reached_first(): void
    {
        $timer = new TestTimer();
        $queue = self::queue('first@example.com', 'second@example.com', 'third@example.com');
        $observer = new RecordingWorkerObserver();

        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use ($timer): void {
            $timer->advance(Duration::seconds(40));
        });

        $runner = new WorkerRunner(
            self::worker($queue, $handlers),
            Duration::seconds(1),
            $observer,
            new WorkerLimits(maxMessages: 10, maxRuntime: Duration::minutes(1)),
            sleep: self::unexpectedSleep(),
            nanoseconds: $timer->now(...),
        );

        $runner->run();

        self::assertSame([WorkerOutcome::Handled, WorkerOutcome::Handled], self::outcomes($observer));
        self::assertSame('third@example.com', $queue->reserve()?->message->payload);
    }

    #[Test]
    public function it_can_run_again_after_stopping_at_a_limit(): void
    {
        $queue = self::queue('first@example.com', 'second@example.com');
        $handled = [];

        $runner = new WorkerRunner(
            self::worker($queue, self::handlers($handled)),
            Duration::seconds(1),
            limits: new WorkerLimits(maxMessages: 1),
            sleep: self::unexpectedSleep(),
        );

        $runner->run();
        $runner->run();

        self::assertEquals(
            [new SendWelcomeEmail('first@example.com'), new SendWelcomeEmail('second@example.com')],
            $handled,
        );
    }

    #[Test]
    public function it_starts_counting_and_timing_again_on_each_run(): void
    {
        $timer = new TestTimer();
        $queue = self::queue('first@example.com', 'second@example.com');
        $observer = new RecordingWorkerObserver();

        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use ($timer): void {
            $timer->advance(Duration::minutes(2));
        });

        $runner = new WorkerRunner(
            self::worker($queue, $handlers),
            Duration::seconds(1),
            $observer,
            new WorkerLimits(maxMessages: 1, maxRuntime: Duration::minutes(1)),
            sleep: self::unexpectedSleep(),
            nanoseconds: $timer->now(...),
        );

        $runner->run();
        $runner->run();

        self::assertSame([WorkerOutcome::Handled, WorkerOutcome::Handled], self::outcomes($observer));
    }

    #[Test]
    public function it_ignores_a_stop_requested_before_it_runs(): void
    {
        $queue = self::queue('ada@example.com');
        $handled = [];

        $runner = new WorkerRunner(
            self::worker($queue, self::handlers($handled)),
            Duration::seconds(1),
            limits: new WorkerLimits(maxMessages: 1),
            sleep: self::unexpectedSleep(),
        );

        $runner->stop();
        $runner->run();

        self::assertEquals([new SendWelcomeEmail('ada@example.com')], $handled);
    }

    #[Test]
    public function it_stops_when_asked_before_any_limit_is_reached(): void
    {
        $timer = new TestTimer();
        $observer = new RecordingWorkerObserver();
        $runner = null;

        $runner = new WorkerRunner(
            self::worker(new InMemoryQueue(), self::handlers()),
            Duration::seconds(1),
            $observer,
            new WorkerLimits(maxMessages: 100, maxRuntime: Duration::hours(1)),
            sleep: static function (Duration $duration) use (&$runner): void {
                $runner?->stop();
            },
            nanoseconds: $timer->now(...),
        );

        $runner->run();

        self::assertSame([WorkerOutcome::Idle], self::outcomes($observer));
    }

    #[Test]
    public function it_can_run_again_after_an_observer_failure_with_limits(): void
    {
        $queue = self::queue('first@example.com', 'second@example.com');
        $failure = new RuntimeException('The metrics backend is unreachable.');
        $observed = [];

        $runner = new WorkerRunner(
            self::worker($queue, self::handlers()),
            Duration::seconds(1),
            new RecordingWorkerObserver(static function (WorkerResult $result) use (&$observed, $failure): void {
                $observed[] = $result;

                if (count($observed) === 1) {
                    throw $failure;
                }
            }),
            new WorkerLimits(maxMessages: 1),
            sleep: self::unexpectedSleep(),
        );

        try {
            $runner->run();
            self::fail('The observer failure did not escape.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        $runner->run();

        self::assertNull($queue->reserve());
    }

    #[Test]
    public function it_measures_the_runtime_with_the_monotonic_clock_by_default(): void
    {
        $runner = new WorkerRunner(
            self::worker(new InMemoryQueue(), self::handlers()),
            Duration::seconds(5),
            limits: new WorkerLimits(maxRuntime: Duration::milliseconds(20)),
        );

        $started = hrtime(true);
        $runner->run();
        $elapsed = hrtime(true) - $started;

        self::assertGreaterThanOrEqual(20_000_000, $elapsed);
        self::assertLessThan(1_000_000_000, $elapsed);
    }

    private static function queue(string ...$emails): InMemoryQueue
    {
        $queue = new InMemoryQueue();

        foreach ($emails as $email) {
            $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, $email));
        }

        return $queue;
    }

    /**
     * @param list<object> $handled
     */
    private static function handlers(array &$handled = []): MessageHandlerRegistry
    {
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use (&$handled): void {
            $handled[] = $message;
        });

        return $handlers;
    }

    private static function failingHandlers(): MessageHandlerRegistry
    {
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message): void {
            throw new RuntimeException('The mail server is unavailable.');
        });

        return $handlers;
    }

    /**
     * @param list<int> $sleeps
     *
     * @return Closure(Duration): void
     */
    private static function advancingSleep(TestTimer $timer, array &$sleeps): Closure
    {
        return static function (Duration $duration) use ($timer, &$sleeps): void {
            $sleeps[] = $duration->milliseconds;
            $timer->advance($duration);
        };
    }

    /**
     * @return Closure(Duration): void
     */
    private static function unexpectedSleep(): Closure
    {
        return static function (Duration $duration): void {
            throw new RuntimeException('The runner slept although it should have stopped.');
        };
    }

    /**
     * @return list<WorkerOutcome>
     */
    private static function outcomes(RecordingWorkerObserver $observer): array
    {
        return array_map(static fn(WorkerResult $result): WorkerOutcome => $result->outcome, $observer->observed);
    }

    private static function worker(
        InMemoryQueue $queue,
        MessageHandlerRegistry $handlers,
        RetryPolicy $retryPolicy = new UnlimitedRetryPolicy(),
    ): Worker {
        return new Worker(
            $queue,
            new SendWelcomeEmailSerializer(),
            $handlers,
            new MessageExecutionPolicyRegistry(new MessageExecutionPolicy($retryPolicy, new NoBackoffPolicy())),
        );
    }
}
