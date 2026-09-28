<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Exception;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Exception\QueueException;
use Dirthara\Queue\Exception\WorkerAlreadyRunningException;

final class WorkerAlreadyRunningExceptionTest extends TestCase
{
    #[Test]
    public function it_carries_nothing_by_default(): void
    {
        $exception = new WorkerAlreadyRunningException();

        self::assertInstanceOf(QueueException::class, $exception);
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertNull($exception->getPrevious());
        self::assertSame([], $exception->context);
    }

    #[Test]
    public function it_keeps_a_previous_exception_and_its_context(): void
    {
        $previous = new RuntimeException('cause');
        $exception = new WorkerAlreadyRunningException('message', 3, $previous, ['worker' => 'default']);

        self::assertSame('message', $exception->getMessage());
        self::assertSame(3, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(['worker' => 'default'], $exception->context);
    }

    #[Test]
    public function it_describes_a_runner_that_is_already_running(): void
    {
        $exception = WorkerAlreadyRunningException::alreadyRunning();

        self::assertSame(
            'Unable to run the worker: it is already running, and a runner runs one loop at a time.',
            $exception->getMessage(),
        );
        self::assertSame([], $exception->context);
    }
}
