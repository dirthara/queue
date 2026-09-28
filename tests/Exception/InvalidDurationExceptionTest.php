<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Exception;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Exception\QueueException;
use Dirthara\Queue\Exception\InvalidDurationException;

use function sprintf;

use const PHP_INT_MAX;

final class InvalidDurationExceptionTest extends TestCase
{
    #[Test]
    public function it_carries_nothing_by_default(): void
    {
        $exception = new InvalidDurationException();

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
        $exception = new InvalidDurationException('message', 3, $previous, ['amount' => -1]);

        self::assertSame('message', $exception->getMessage());
        self::assertSame(3, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(['amount' => -1], $exception->context);
    }

    #[Test]
    public function it_merges_what_is_added_to_its_context(): void
    {
        $exception = new InvalidDurationException(context: ['amount' => -1, 'kept' => true]);

        self::assertSame($exception, $exception->addContext(['amount' => -2, 'queue' => 'default']));
        self::assertSame(['amount' => -2, 'kept' => true, 'queue' => 'default'], $exception->context);
    }

    #[Test]
    public function it_describes_a_negative_duration(): void
    {
        $exception = InvalidDurationException::negative(-5, 'seconds');

        self::assertSame(
            'Unable to create a duration of -5 seconds: a duration cannot be negative.',
            $exception->getMessage(),
        );
        self::assertSame(['amount' => -5, 'unit' => 'seconds'], $exception->context);
    }

    #[Test]
    public function it_describes_a_duration_that_does_not_fit_in_milliseconds(): void
    {
        $exception = InvalidDurationException::tooLong(PHP_INT_MAX, 'hours');

        self::assertSame(
            sprintf(
                'Unable to create a duration of %d hours: it does not fit in a whole number of milliseconds.',
                PHP_INT_MAX,
            ),
            $exception->getMessage(),
        );
        self::assertSame(['amount' => PHP_INT_MAX, 'unit' => 'hours'], $exception->context);
    }
}
