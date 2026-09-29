<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Exception;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Exception\QueueException;
use Dirthara\Queue\Exception\InvalidWorkerRunnerException;

final class InvalidWorkerRunnerExceptionTest extends TestCase
{
    #[Test]
    public function it_carries_nothing_by_default(): void
    {
        $exception = new InvalidWorkerRunnerException();

        self::assertInstanceOf(QueueException::class, $exception);
        self::assertInstanceOf(InvalidArgumentException::class, $exception);
        self::assertSame('', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertNull($exception->getPrevious());
        self::assertSame([], $exception->context);
    }

    #[Test]
    public function it_keeps_a_previous_exception_and_its_context(): void
    {
        $previous = new InvalidArgumentException('cause');
        $exception = new InvalidWorkerRunnerException('message', 3, $previous, ['idleDelay' => 0]);

        self::assertSame('message', $exception->getMessage());
        self::assertSame(3, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(['idleDelay' => 0], $exception->context);
    }

    #[Test]
    public function it_merges_what_is_added_to_its_context(): void
    {
        $exception = new InvalidWorkerRunnerException(context: ['idleDelay' => 0, 'kept' => true]);

        self::assertSame($exception, $exception->addContext(['idleDelay' => -1, 'queue' => 'default']));
        self::assertSame(['idleDelay' => -1, 'kept' => true, 'queue' => 'default'], $exception->context);
    }

    #[Test]
    public function it_describes_an_idle_delay_of_zero(): void
    {
        $exception = InvalidWorkerRunnerException::noIdleDelay();

        self::assertSame(
            'Unable to run a worker with an idle delay of 0 milliseconds: '
            . 'an idle worker would poll its queue without pausing, so the delay has to be at least 1 millisecond.',
            $exception->getMessage(),
        );
        self::assertSame(['idleDelay' => 0], $exception->context);
    }
}
