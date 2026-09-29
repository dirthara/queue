<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests;

use Closure;
use RuntimeException;
use Dirthara\Queue\Worker;
use PHPUnit\Framework\TestCase;
use Dirthara\Queue\WorkerRunner;
use Dirthara\Queue\WorkerOutcome;
use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\Delivery;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Contract\RetryPolicy;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\MessageHandlerRegistry;
use Dirthara\Queue\Retry\NeverRetryPolicy;
use Dirthara\Queue\Backoff\NoBackoffPolicy;
use Dirthara\Queue\ValueObject\WorkerResult;
use Dirthara\Queue\Retry\AttemptsRetryPolicy;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Queue\Retry\UnlimitedRetryPolicy;
use Dirthara\Queue\Driver\Memory\InMemoryQueue;
use Dirthara\Queue\MessageExecutionPolicyRegistry;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmail;
use Dirthara\Queue\ValueObject\MessageExecutionPolicy;
use Dirthara\Queue\Exception\InvalidWorkerRunnerException;
use Dirthara\Queue\Tests\Fixtures\RecordingWorkerObserver;
use Dirthara\Queue\Exception\WorkerAlreadyRunningException;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmailSerialiser;

use function count;
use function hrtime;
use function array_map;

final class WorkerRunnerTest extends TestCase
{
    #[Test]
    public function it_sleeps_for_the_idle_delay_whenever_the_queue_is_empty(): void
    {
        $slept = [];
        $runner = null;
        $idleDelay = Duration::seconds(2);
        $runner = new WorkerRunner(
            self::worker(new InMemoryQueue(), new MessageHandlerRegistry()),
            $idleDelay,
            sleep: static function (Duration $duration) use (&$slept, &$runner): void {
                $slept[] = $duration;

                if (count($slept) === 3) {
                    $runner?->stop();
                }
            },
        );

        $runner->run();

        self::assertSame([$idleDelay, $idleDelay, $idleDelay], $slept);
    }

    #[Test]
    public function it_handles_waiting_messages_without_sleeping_between_them(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'first@example.com'));
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'second@example.com'));

        $events = [];
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use (&$events): void {
            $events[] = 'handled';
        });

        $runner = null;
        $runner = new WorkerRunner(
            self::worker($queue, $handlers),
            Duration::seconds(1),
            sleep: static function (Duration $duration) use (&$events, &$runner): void {
                $events[] = 'slept';
                $runner?->stop();
            },
        );

        $runner->run();

        self::assertSame(['handled', 'handled', 'slept'], $events);
    }

    #[Test]
    public function it_keeps_running_after_a_handler_fails_without_sleeping(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'fails@example.com'));
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'succeeds@example.com'));

        $events = [];
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use (&$events): void {
            if ($message instanceof SendWelcomeEmail && $message->email === 'fails@example.com') {
                $events[] = 'failed';

                throw new RuntimeException('The mail server is unavailable.');
            }

            $events[] = 'handled';
        });

        $runner = null;
        $runner = new WorkerRunner(
            self::worker($queue, $handlers, new NeverRetryPolicy()),
            Duration::seconds(1),
            sleep: static function (Duration $duration) use (&$events, &$runner): void {
                $events[] = 'slept';
                $runner?->stop();
            },
        );

        $runner->run();

        self::assertSame(['failed', 'handled', 'slept'], $events);
        self::assertCount(1, $queue->failed());
    }

    #[Test]
    public function it_finishes_the_current_message_and_then_stops_when_asked_to_from_a_handler(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'first@example.com'));
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'second@example.com'));

        $runner = null;
        $handled = [];
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use (&$handled, &$runner): void {
            $handled[] = $message;
            $runner?->stop();
        });

        $slept = false;
        $runner = new WorkerRunner(
            self::worker($queue, $handlers),
            Duration::seconds(1),
            sleep: static function (Duration $duration) use (&$slept): void {
                $slept = true;
            },
        );

        $runner->run();

        self::assertEquals([new SendWelcomeEmail('first@example.com')], $handled);
        self::assertFalse($slept);
        self::assertSame('second@example.com', $queue->reserve()?->message->payload);
    }

    #[Test]
    public function it_can_run_again_after_it_was_stopped(): void
    {
        $sleeps = [];
        $runner = null;
        $runner = new WorkerRunner(
            self::worker(new InMemoryQueue(), new MessageHandlerRegistry()),
            Duration::seconds(1),
            sleep: static function (Duration $duration) use (&$sleeps, &$runner): void {
                $sleeps[] = $duration;
                $runner?->stop();
            },
        );

        $runner->run();
        $runner->run();

        self::assertCount(2, $sleeps);
    }

    #[Test]
    public function it_refuses_to_run_while_it_is_already_running(): void
    {
        $refused = null;
        $runner = null;
        $runner = new WorkerRunner(
            self::worker(new InMemoryQueue(), new MessageHandlerRegistry()),
            Duration::seconds(1),
            sleep: static function (Duration $duration) use (&$refused, &$runner): void {
                try {
                    $runner?->run();
                } catch (WorkerAlreadyRunningException $exception) {
                    $refused = $exception;
                }

                $runner?->stop();
            },
        );

        $runner->run();

        self::assertInstanceOf(WorkerAlreadyRunningException::class, $refused);
    }

    #[Test]
    public function it_stops_and_can_run_again_when_the_worker_throws(): void
    {
        $failure = new RuntimeException('The queue is unreachable.');
        $queue = new class($failure) implements Queue {
            public int $reservations = 0;

            public function __construct(
                private readonly RuntimeException $failure,
            ) {}

            public function enqueue(QueuedMessage $message, ?Duration $delay = null): void {}

            public function reserve(): ?Delivery
            {
                $this->reservations++;

                throw $this->failure;
            }
        };

        $runner = new WorkerRunner(
            self::worker($queue, new MessageHandlerRegistry()),
            Duration::seconds(1),
            sleep: static function (Duration $duration): void {},
        );

        for ($run = 1; $run <= 2; $run++) {
            try {
                $runner->run();
                self::fail('The worker failure was not rethrown.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
                self::assertSame($run, $queue->reservations);
            }
        }
    }

    #[Test]
    public function it_sleeps_natively_by_default(): void
    {
        $runner = null;
        $queue = new class(static function () use (&$runner): void {
            $runner?->stop();
        }) implements Queue {
            private int $reservations = 0;

            public function __construct(
                private readonly Closure $onSecondReservation,
            ) {}

            public function enqueue(QueuedMessage $message, ?Duration $delay = null): void {}

            public function reserve(): ?Delivery
            {
                $this->reservations++;

                if ($this->reservations === 2) {
                    ($this->onSecondReservation)();
                }

                return null;
            }
        };

        $runner = new WorkerRunner(self::worker($queue, new MessageHandlerRegistry()), Duration::milliseconds(20));

        $started = hrtime(true);
        $runner->run();
        $elapsed = hrtime(true) - $started;

        self::assertGreaterThanOrEqual(20_000_000, $elapsed);
    }

    #[Test]
    public function it_observes_every_result_in_the_order_it_was_produced(): void
    {
        $queue = new InMemoryQueue();
        $handled = new QueuedMessage(SendWelcomeEmail::class, 'handled@example.com');
        $failed = new QueuedMessage(SendWelcomeEmail::class, 'fails@example.com');
        $queue->enqueue($handled);
        $queue->enqueue($failed);
        $failure = new RuntimeException('The mail server is unavailable.');

        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use ($failure): void {
            if ($message instanceof SendWelcomeEmail && $message->email === 'fails@example.com') {
                throw $failure;
            }
        });

        $runner = null;
        $observer = new RecordingWorkerObserver();
        $runner = new WorkerRunner(
            self::worker($queue, $handlers, new NeverRetryPolicy()),
            Duration::seconds(1),
            $observer,
            sleep: static function (Duration $duration) use (&$runner): void {
                $runner?->stop();
            },
        );

        $runner->run();

        self::assertSame(
            [WorkerOutcome::Handled, WorkerOutcome::Failed, WorkerOutcome::Idle],
            array_map(static fn(WorkerResult $result): WorkerOutcome => $result->outcome, $observer->observed),
        );
        self::assertSame($handled, $observer->observed[0]->message);
        self::assertSame(1, $observer->observed[0]->attempt);
        self::assertSame($failed, $observer->observed[1]->message);
        self::assertSame($failure, $observer->observed[1]->failure);
        self::assertNull($observer->observed[2]->message);
    }

    #[Test]
    public function it_observes_a_result_before_sleeping_on_it(): void
    {
        $events = [];
        $runner = null;
        $runner = new WorkerRunner(
            self::worker(new InMemoryQueue(), new MessageHandlerRegistry()),
            Duration::seconds(1),
            new RecordingWorkerObserver(static function (WorkerResult $result) use (&$events): void {
                $events[] = 'observed';
            }),
            sleep: static function (Duration $duration) use (&$events, &$runner): void {
                $events[] = 'slept';

                if (count($events) === 4) {
                    $runner?->stop();
                }
            },
        );

        $runner->run();

        self::assertSame(['observed', 'slept', 'observed', 'slept'], $events);
    }

    #[Test]
    public function it_observes_a_released_attempt_distinctly_from_the_final_failure(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com'));
        $failure = new RuntimeException('The mail server is unavailable.');

        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use ($failure): void {
            throw $failure;
        });

        $runner = null;
        $observer = new RecordingWorkerObserver();
        $runner = new WorkerRunner(
            self::worker($queue, $handlers, new AttemptsRetryPolicy(2)),
            Duration::seconds(1),
            $observer,
            sleep: static function (Duration $duration) use (&$runner): void {
                $runner?->stop();
            },
        );

        $runner->run();

        self::assertSame(
            [WorkerOutcome::Released, WorkerOutcome::Failed, WorkerOutcome::Idle],
            array_map(static fn(WorkerResult $result): WorkerOutcome => $result->outcome, $observer->observed),
        );
        self::assertSame($failure, $observer->observed[0]->failure);
        self::assertSame($failure, $observer->observed[1]->failure);
    }

    #[Test]
    public function it_observes_every_retry_of_a_failing_message(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com');
        $queue->enqueue($message);

        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message): void {
            throw new RuntimeException('The mail server is unavailable.');
        });

        $runner = null;
        $observer = new RecordingWorkerObserver();
        $runner = new WorkerRunner(
            self::worker($queue, $handlers, new AttemptsRetryPolicy(3)),
            Duration::seconds(1),
            $observer,
            sleep: static function (Duration $duration) use (&$runner): void {
                $runner?->stop();
            },
        );

        $runner->run();

        self::assertSame(
            [1, 2, 3, null],
            array_map(static fn(WorkerResult $result): ?int => $result->attempt, $observer->observed),
        );
        self::assertCount(1, $queue->failed());
    }

    #[Test]
    public function it_lets_an_observer_failure_escape_and_can_run_again(): void
    {
        $failure = new RuntimeException('The metrics backend is unreachable.');
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'first@example.com'));
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'second@example.com'));

        $handled = [];
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use (&$handled): void {
            $handled[] = $message;
        });

        $runner = new WorkerRunner(
            self::worker($queue, $handlers),
            Duration::seconds(1),
            new RecordingWorkerObserver(static function (WorkerResult $result) use ($failure): void {
                throw $failure;
            }),
            sleep: static function (Duration $duration): void {},
        );

        for ($run = 1; $run <= 2; $run++) {
            try {
                $runner->run();
                self::fail('The observer failure did not escape.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
                self::assertCount($run, $handled);
            }
        }

        self::assertNull($queue->reserve());
    }

    #[Test]
    public function it_needs_no_observer(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com'));

        $handled = [];
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use (&$handled): void {
            $handled[] = $message;
        });

        $runner = null;
        $runner = new WorkerRunner(
            self::worker($queue, $handlers),
            Duration::seconds(1),
            sleep: static function (Duration $duration) use (&$runner): void {
                $runner?->stop();
            },
        );

        $runner->run();

        self::assertEquals([new SendWelcomeEmail('ada@example.com')], $handled);
    }

    #[Test]
    public function it_accepts_an_idle_delay_of_one_millisecond(): void
    {
        $slept = [];
        $runner = null;
        $runner = new WorkerRunner(
            self::worker(new InMemoryQueue(), new MessageHandlerRegistry()),
            Duration::milliseconds(1),
            sleep: static function (Duration $duration) use (&$slept, &$runner): void {
                $slept[] = $duration->milliseconds;
                $runner?->stop();
            },
        );

        $runner->run();

        self::assertSame([1], $slept);
    }

    #[Test]
    public function it_refuses_an_idle_delay_of_zero(): void
    {
        try {
            new WorkerRunner(
                self::worker(new InMemoryQueue(), new MessageHandlerRegistry()),
                Duration::milliseconds(0),
            );
            self::fail('An idle delay of zero was accepted.');
        } catch (InvalidWorkerRunnerException $exception) {
            self::assertSame(['idleDelay' => 0], $exception->context);
        }
    }

    private static function worker(
        Queue $queue,
        MessageHandlerRegistry $handlers,
        RetryPolicy $retryPolicy = new UnlimitedRetryPolicy(),
    ): Worker {
        return new Worker(
            $queue,
            new SendWelcomeEmailSerialiser(),
            $handlers,
            new MessageExecutionPolicyRegistry(new MessageExecutionPolicy($retryPolicy, new NoBackoffPolicy())),
        );
    }
}
