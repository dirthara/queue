<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Exception;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Exception\QueueException;
use Dirthara\Queue\Exception\InvalidRetryPolicyException;

final class InvalidRetryPolicyExceptionTest extends TestCase
{
    #[Test]
    public function it_carries_nothing_by_default(): void
    {
        $exception = new InvalidRetryPolicyException();

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
        $exception = new InvalidRetryPolicyException('message', 3, $previous, ['maxAttempts' => 0]);

        self::assertSame('message', $exception->getMessage());
        self::assertSame(3, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(['maxAttempts' => 0], $exception->context);
    }

    #[Test]
    public function it_merges_what_is_added_to_its_context(): void
    {
        $exception = new InvalidRetryPolicyException(context: ['maxAttempts' => 0, 'kept' => true]);

        self::assertSame($exception, $exception->addContext(['maxAttempts' => -1, 'queue' => 'default']));
        self::assertSame(['maxAttempts' => -1, 'kept' => true, 'queue' => 'default'], $exception->context);
    }

    #[Test]
    public function it_describes_too_few_attempts(): void
    {
        $exception = InvalidRetryPolicyException::tooFewAttempts(0);

        self::assertSame(
            'Unable to limit a message to 0 attempts: a message is always attempted at least once.',
            $exception->getMessage(),
        );
        self::assertSame(['maxAttempts' => 0], $exception->context);
    }
}
