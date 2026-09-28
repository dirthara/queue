<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Exception;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Exception\QueueException;
use Dirthara\Queue\Exception\DuplicateQueueDriverException;

final class DuplicateQueueDriverExceptionTest extends TestCase
{
    #[Test]
    public function it_carries_nothing_by_default(): void
    {
        $exception = new DuplicateQueueDriverException();

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
        $exception = new DuplicateQueueDriverException('message', 3, $previous, ['driver' => 'Missing']);

        self::assertSame('message', $exception->getMessage());
        self::assertSame(3, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(['driver' => 'Missing'], $exception->context);
    }

    #[Test]
    public function it_merges_what_is_added_to_its_context(): void
    {
        $exception = new DuplicateQueueDriverException(context: ['driver' => 'Missing', 'kept' => true]);

        self::assertSame($exception, $exception->addContext(['driver' => 'Replaced', 'queue' => 'default']));
        self::assertSame(['driver' => 'Replaced', 'kept' => true, 'queue' => 'default'], $exception->context);
    }

    #[Test]
    public function it_describes_a_driver_name_that_is_already_taken(): void
    {
        $exception = DuplicateQueueDriverException::alreadyRegistered("class@anonymous\0/app/src/Job.php:3$0");

        self::assertSame(
            'Unable to register the queue driver "class@anonymous\\000/app/src/Job.php:3$0": a driver is already registered under that name, and a driver is never replaced.',
            $exception->getMessage(),
        );
        self::assertSame(['driver' => 'class@anonymous\\000/app/src/Job.php:3$0'], $exception->context);
    }
}
