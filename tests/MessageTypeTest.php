<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests;

use Dirthara\Queue\MessageType;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Tests\Fixtures\Maintenance;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Queue\Tests\Fixtures\CustomerMessage;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmail;
use Dirthara\Queue\Exception\InvalidMessageTypeException;
use Dirthara\Queue\Tests\Fixtures\Inheritance\PaymentMessage;

use function strtoupper;

final class MessageTypeTest extends TestCase
{
    #[Test]
    public function it_accepts_a_concrete_class_and_an_enum(): void
    {
        self::assertSame(SendWelcomeEmail::class, MessageType::exact(SendWelcomeEmail::class));
        self::assertSame(Maintenance::class, MessageType::exact(Maintenance::class));
    }

    #[Test]
    public function it_returns_the_declared_spelling_of_the_type(): void
    {
        self::assertSame(SendWelcomeEmail::class, MessageType::exact('\\' . strtoupper(SendWelcomeEmail::class)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTypes(): iterable
    {
        yield 'an empty string' => ['', 'a message type has to be an existing class or enum'];
        yield 'an unknown class' => ['App\\DoesNotExist', 'a message type has to be an existing class or enum'];
        yield 'a scalar type' => ['string', 'a message type has to be an existing class or enum'];
        yield 'an interface' => [CustomerMessage::class, 'it is an interface'];
        yield 'an abstract class' => [PaymentMessage::class, 'it is an abstract class'];
    }

    #[Test]
    #[DataProvider('invalidTypes')]
    public function it_rejects_a_type_no_message_has_as_its_exact_class(string $message, string $reason): void
    {
        try {
            MessageType::exact($message);
            self::fail('An invalid message type was accepted.');
        } catch (InvalidMessageTypeException $exception) {
            self::assertStringContainsString($reason, $exception->getMessage());
            self::assertSame(['message' => $message], $exception->context);
        }
    }
}
