<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Exception;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Exception\QueueException;
use Dirthara\Queue\Exception\FailedMessageNotFoundException;

final class FailedMessageNotFoundExceptionTest extends TestCase
{
    #[Test]
    public function it_carries_nothing_by_default(): void
    {
        $exception = new FailedMessageNotFoundException();

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
        $exception = new FailedMessageNotFoundException('message', 3, $previous, ['id' => 'Missing']);

        self::assertSame('message', $exception->getMessage());
        self::assertSame(3, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(['id' => 'Missing'], $exception->context);
    }

    #[Test]
    public function it_merges_what_is_added_to_its_context(): void
    {
        $exception = new FailedMessageNotFoundException(context: ['id' => 'Missing', 'kept' => true]);

        self::assertSame($exception, $exception->addContext(['id' => 'Replaced', 'queue' => 'default']));
        self::assertSame(['id' => 'Replaced', 'kept' => true, 'queue' => 'default'], $exception->context);
    }

    #[Test]
    public function it_describes_an_id_without_a_failed_message(): void
    {
        $exception = FailedMessageNotFoundException::forId("abc\ndef");

        self::assertSame(
            'Unable to find the failed message "abc\\ndef": no failed message has that id.',
            $exception->getMessage(),
        );
        self::assertSame(['id' => 'abc\\ndef'], $exception->context);
    }
}
