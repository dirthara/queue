<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Exception;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Exception\QueueException;
use Dirthara\Queue\Exception\InvalidMessageTypeException;

final class InvalidMessageTypeExceptionTest extends TestCase
{
    #[Test]
    public function it_carries_nothing_by_default(): void
    {
        $exception = new InvalidMessageTypeException();

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
        $exception = new InvalidMessageTypeException('message', 3, $previous, ['message' => 'Missing']);

        self::assertSame('message', $exception->getMessage());
        self::assertSame(3, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(['message' => 'Missing'], $exception->context);
    }

    #[Test]
    public function it_merges_what_is_added_to_its_context(): void
    {
        $exception = new InvalidMessageTypeException(context: ['message' => 'Missing', 'kept' => true]);

        self::assertSame($exception, $exception->addContext(['message' => 'Replaced', 'queue' => 'default']));
        self::assertSame(['message' => 'Replaced', 'kept' => true, 'queue' => 'default'], $exception->context);
    }

    #[Test]
    public function it_describes_a_type_no_object_can_have(): void
    {
        $exception = InvalidMessageTypeException::notAnObjectType("class@anonymous\0/app/src/Job.php:3$0");

        self::assertSame(
            'Unable to register a handler for "class@anonymous\\000/app/src/Job.php:3$0": a message type has to be an existing class or enum.',
            $exception->getMessage(),
        );
        self::assertSame(['message' => 'class@anonymous\\000/app/src/Job.php:3$0'], $exception->context);
    }

    #[Test]
    public function it_describes_an_interface(): void
    {
        $exception = InvalidMessageTypeException::interfaceType("class@anonymous\0/app/src/Job.php:3$0");

        self::assertSame(
            'Unable to register a handler for "class@anonymous\\000/app/src/Job.php:3$0": it is an interface, and messages are handled by their exact class.',
            $exception->getMessage(),
        );
        self::assertSame(['message' => 'class@anonymous\\000/app/src/Job.php:3$0'], $exception->context);
    }

    #[Test]
    public function it_describes_an_abstract_class(): void
    {
        $exception = InvalidMessageTypeException::abstractClass("class@anonymous\0/app/src/Job.php:3$0");

        self::assertSame(
            'Unable to register a handler for "class@anonymous\\000/app/src/Job.php:3$0": it is an abstract class, and messages are handled by their exact class.',
            $exception->getMessage(),
        );
        self::assertSame(['message' => 'class@anonymous\\000/app/src/Job.php:3$0'], $exception->context);
    }
}
