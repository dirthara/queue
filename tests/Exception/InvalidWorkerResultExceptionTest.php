<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Exception;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Exception\QueueException;
use Dirthara\Queue\Exception\InvalidWorkerResultException;

final class InvalidWorkerResultExceptionTest extends TestCase
{
    #[Test]
    public function it_carries_nothing_by_default(): void
    {
        $exception = new InvalidWorkerResultException();

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
        $exception = new InvalidWorkerResultException('message', 3, $previous, ['attempt' => 0]);

        self::assertSame('message', $exception->getMessage());
        self::assertSame(3, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(['attempt' => 0], $exception->context);
    }

    #[Test]
    public function it_merges_what_is_added_to_its_context(): void
    {
        $exception = new InvalidWorkerResultException(context: ['attempt' => 0, 'kept' => true]);

        self::assertSame($exception, $exception->addContext(['attempt' => -1, 'queue' => 'default']));
        self::assertSame(['attempt' => -1, 'kept' => true, 'queue' => 'default'], $exception->context);
    }

    #[Test]
    public function it_describes_an_attempt_before_the_first(): void
    {
        $exception = InvalidWorkerResultException::attemptBeforeFirst(0);

        self::assertSame(
            'Unable to describe the result of attempt 0: attempts are counted from 1.',
            $exception->getMessage(),
        );
        self::assertSame(['attempt' => 0], $exception->context);
    }
}
