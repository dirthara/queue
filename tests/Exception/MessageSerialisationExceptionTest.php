<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Exception;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Exception\QueueException;
use Dirthara\Queue\Exception\MessageSerialisationException;

final class MessageSerialisationExceptionTest extends TestCase
{
    #[Test]
    public function it_carries_nothing_by_default(): void
    {
        $exception = new MessageSerialisationException();

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
        $exception = new MessageSerialisationException('message', 3, $previous, ['message' => 'Missing']);

        self::assertSame('message', $exception->getMessage());
        self::assertSame(3, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(['message' => 'Missing'], $exception->context);
    }

    #[Test]
    public function it_merges_what_is_added_to_its_context(): void
    {
        $exception = new MessageSerialisationException(context: ['message' => 'Missing', 'kept' => true]);

        self::assertSame($exception, $exception->addContext(['message' => 'Replaced', 'queue' => 'default']));
        self::assertSame(['message' => 'Replaced', 'kept' => true, 'queue' => 'default'], $exception->context);
    }

    #[Test]
    public function it_describes_a_message_that_cannot_be_serialised(): void
    {
        $exception = MessageSerialisationException::unableToSerialise(
            "class@anonymous\0/app/src/Job.php:3$0",
            $previous = new RuntimeException('cause'),
        );

        self::assertSame(
            'Unable to serialise a message of type "class@anonymous\\000/app/src/Job.php:3$0".',
            $exception->getMessage(),
        );
        self::assertSame(['message' => 'class@anonymous\\000/app/src/Job.php:3$0'], $exception->context);
        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function it_describes_a_malformed_payload(): void
    {
        $exception = MessageSerialisationException::unableToDeserialise("class@anonymous\0/app/src/Job.php:3$0");

        self::assertSame(
            'Unable to deserialise a message of type "class@anonymous\\000/app/src/Job.php:3$0": the payload is malformed.',
            $exception->getMessage(),
        );
        self::assertSame(['message' => 'class@anonymous\\000/app/src/Job.php:3$0'], $exception->context);
        self::assertNull($exception->getPrevious());
    }

    #[Test]
    public function it_describes_a_payload_that_is_not_an_object(): void
    {
        $exception = MessageSerialisationException::notAnObject("class@anonymous\0/app/src/Job.php:3$0", 'int');

        self::assertSame(
            'Unable to deserialise a message of type "class@anonymous\\000/app/src/Job.php:3$0": the payload holds int, not an object.',
            $exception->getMessage(),
        );
        self::assertSame(
            ['message' => 'class@anonymous\\000/app/src/Job.php:3$0', 'actual' => 'int'],
            $exception->context,
        );
        self::assertNull($exception->getPrevious());
    }

    #[Test]
    public function it_describes_a_payload_of_another_type(): void
    {
        $exception = MessageSerialisationException::typeMismatch(
            "class@anonymous\0/app/src/Job.php:3$0",
            "App\\Other\n",
        );

        self::assertSame(
            'Unable to deserialise a message of type "class@anonymous\\000/app/src/Job.php:3$0": the payload holds a "App\\Other\\n".',
            $exception->getMessage(),
        );
        self::assertSame(
            ['message' => 'class@anonymous\\000/app/src/Job.php:3$0', 'actual' => 'App\\Other\\n'],
            $exception->context,
        );
        self::assertNull($exception->getPrevious());
    }
}
