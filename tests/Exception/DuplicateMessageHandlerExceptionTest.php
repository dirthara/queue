<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Exception;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Exception\QueueException;
use Dirthara\Queue\Exception\DuplicateMessageHandlerException;

final class DuplicateMessageHandlerExceptionTest extends TestCase
{
    #[Test]
    public function it_carries_nothing_by_default(): void
    {
        $exception = new DuplicateMessageHandlerException();

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
        $exception = new DuplicateMessageHandlerException('message', 3, $previous, ['message' => 'Missing']);

        self::assertSame('message', $exception->getMessage());
        self::assertSame(3, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(['message' => 'Missing'], $exception->context);
    }

    #[Test]
    public function it_merges_what_is_added_to_its_context(): void
    {
        $exception = new DuplicateMessageHandlerException(context: ['message' => 'Missing', 'kept' => true]);

        self::assertSame($exception, $exception->addContext(['message' => 'Replaced', 'queue' => 'default']));
        self::assertSame(['message' => 'Replaced', 'kept' => true, 'queue' => 'default'], $exception->context);
    }

    #[Test]
    public function it_describes_a_message_type_that_already_has_a_handler(): void
    {
        $exception = DuplicateMessageHandlerException::alreadyRegistered("class@anonymous\0/app/src/Job.php:3$0");

        self::assertSame(
            'Unable to register a handler for "class@anonymous\\000/app/src/Job.php:3$0": the message type already has a handler, and a handler is never replaced.',
            $exception->getMessage(),
        );
        self::assertSame(['message' => 'class@anonymous\\000/app/src/Job.php:3$0'], $exception->context);
    }
}
