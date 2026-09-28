<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Exception;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Exception\QueueException;
use Dirthara\Queue\Tests\Fixtures\ContextualException;

final class HasExceptionContextTest extends TestCase
{
    #[Test]
    public function it_starts_without_context(): void
    {
        $exception = new ContextualException();

        self::assertInstanceOf(QueueException::class, $exception);
        self::assertSame([], $exception->context);
    }

    #[Test]
    public function it_merges_what_is_added_to_its_context(): void
    {
        $exception = new ContextualException();
        $exception->addContext(['field' => 'email', 'kept' => true]);

        self::assertSame($exception, $exception->addContext(['field' => 'name', 'rule' => 'required']));
        self::assertSame(['field' => 'name', 'kept' => true, 'rule' => 'required'], $exception->context);
    }

    #[Test]
    public function it_escapes_control_characters_in_a_printable_value(): void
    {
        self::assertSame('plain', ContextualException::describe('plain'));
        self::assertSame('a\\nb\\000c\\177', ContextualException::describe("a\nb\0c\x7f"));
    }
}
