<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\ValueObject;

use LogicException;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\ValueObject\Failure;

final class FailureTest extends TestCase
{
    #[Test]
    public function it_carries_a_type_message_and_code(): void
    {
        $failure = new Failure(RuntimeException::class, 'The handler failed.', 42);

        self::assertSame(RuntimeException::class, $failure->type);
        self::assertSame('The handler failed.', $failure->message);
        self::assertSame(42, $failure->code);
    }

    #[Test]
    public function it_summarises_a_throwable(): void
    {
        $failure = Failure::fromThrowable(new RuntimeException('The mail server is unavailable.', 503));

        self::assertEquals(new Failure(RuntimeException::class, 'The mail server is unavailable.', 503), $failure);
    }

    #[Test]
    public function it_keeps_the_exact_class_of_the_throwable(): void
    {
        $throwable = new class('anonymous') extends LogicException {};

        self::assertSame($throwable::class, Failure::fromThrowable($throwable)->type);
    }

    #[Test]
    public function it_keeps_a_string_code(): void
    {
        $throwable = new class('SQLSTATE[HY000]') extends RuntimeException {
            protected $code = 'HY000';
        };

        self::assertSame('HY000', Failure::fromThrowable($throwable)->code);
    }

    #[Test]
    public function it_leaves_the_previous_throwable_out(): void
    {
        $failure = Failure::fromThrowable(new RuntimeException('outer', 0, new RuntimeException('inner')));

        self::assertEquals(new Failure(RuntimeException::class, 'outer', 0), $failure);
    }
}
