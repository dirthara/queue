<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Exception;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Exception\QueueException;
use Dirthara\Queue\Exception\InvalidWorkerLimitsException;

final class InvalidWorkerLimitsExceptionTest extends TestCase
{
    #[Test]
    public function it_carries_nothing_by_default(): void
    {
        $exception = new InvalidWorkerLimitsException();

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
        $exception = new InvalidWorkerLimitsException('message', 3, $previous, ['maxMessages' => 0]);

        self::assertSame('message', $exception->getMessage());
        self::assertSame(3, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(['maxMessages' => 0], $exception->context);
    }

    #[Test]
    public function it_merges_what_is_added_to_its_context(): void
    {
        $exception = new InvalidWorkerLimitsException(context: ['maxMessages' => 0, 'kept' => true]);

        self::assertSame($exception, $exception->addContext(['maxMessages' => -1, 'queue' => 'default']));
        self::assertSame(['maxMessages' => -1, 'kept' => true, 'queue' => 'default'], $exception->context);
    }

    #[Test]
    public function it_describes_too_few_messages(): void
    {
        $exception = InvalidWorkerLimitsException::tooFewMessages(0);

        self::assertSame(
            'Unable to limit a worker to 0 messages: a limited worker processes at least one message.',
            $exception->getMessage(),
        );
        self::assertSame(['maxMessages' => 0], $exception->context);
    }

    #[Test]
    public function it_describes_a_runtime_of_zero(): void
    {
        $exception = InvalidWorkerLimitsException::noRuntime();

        self::assertSame(
            'Unable to limit a worker to a runtime of 0 milliseconds: a limited worker runs for at least 1 millisecond.',
            $exception->getMessage(),
        );
        self::assertSame(['maxRuntime' => 0], $exception->context);
    }
}
