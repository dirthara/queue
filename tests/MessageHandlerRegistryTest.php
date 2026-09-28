<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\MessageHandlerRegistry;
use Dirthara\Queue\Tests\Fixtures\Maintenance;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Queue\Tests\Fixtures\Inheritance\TakePayment;

use function strtolower;
use function strtoupper;

use Dirthara\Queue\Tests\Fixtures\CustomerMessage;
use Dirthara\Queue\Tests\Fixtures\GenerateInvoice;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmail;
use Dirthara\Queue\Exception\InvalidMessageTypeException;
use Dirthara\Queue\Exception\MessageHandlerNotFoundException;
use Dirthara\Queue\Tests\Fixtures\Inheritance\PaymentMessage;
use Dirthara\Queue\Exception\DuplicateMessageHandlerException;

final class MessageHandlerRegistryTest extends TestCase
{
    #[Test]
    public function it_provides_the_handler_registered_for_the_message_type(): void
    {
        $registry = new MessageHandlerRegistry();
        $welcome = static function (object $message): void {};
        $invoice = static function (object $message): void {};

        $registry->register(SendWelcomeEmail::class, $welcome);
        $registry->register(GenerateInvoice::class, $invoice);

        self::assertSame($welcome, $registry->handlerFor(new SendWelcomeEmail('ada@example.com')));
        self::assertSame($invoice, $registry->handlerFor(new GenerateInvoice('INV-1')));
    }

    #[Test]
    public function it_provides_the_handler_for_an_enum_case_by_its_enum(): void
    {
        $registry = new MessageHandlerRegistry();
        $handler = static function (object $message): void {};

        $registry->register(Maintenance::class, $handler);

        self::assertSame($handler, $registry->handlerFor(Maintenance::PurgeExpiredSessions));
    }

    #[Test]
    public function it_provides_the_handler_for_a_type_registered_in_other_letter_case_or_with_a_leading_backslash(): void
    {
        $registry = new MessageHandlerRegistry();
        $welcome = static function (object $message): void {};
        $invoice = static function (object $message): void {};

        $registry->register('\\' . SendWelcomeEmail::class, $welcome);
        $registry->register(strtolower(GenerateInvoice::class), $invoice);

        self::assertSame($welcome, $registry->handlerFor(new SendWelcomeEmail('ada@example.com')));
        self::assertSame($invoice, $registry->handlerFor(new GenerateInvoice('INV-1')));
    }

    #[Test]
    public function it_provides_the_handler_for_the_concrete_class_of_an_abstract_parent(): void
    {
        $registry = new MessageHandlerRegistry();
        $handler = static function (object $message): void {};

        $registry->register(TakePayment::class, $handler);

        self::assertSame($handler, $registry->handlerFor(new TakePayment('PAY-1')));
    }

    #[Test]
    public function it_rejects_a_second_handler_for_the_same_message_type(): void
    {
        $registry = new MessageHandlerRegistry();
        $first = static function (object $message): void {};
        $registry->register(SendWelcomeEmail::class, $first);

        try {
            $registry->register(SendWelcomeEmail::class, static function (object $message): void {});
            self::fail('A second handler for the same message type was accepted.');
        } catch (DuplicateMessageHandlerException $exception) {
            self::assertSame(['message' => SendWelcomeEmail::class], $exception->context);
        }

        self::assertSame($first, $registry->handlerFor(new SendWelcomeEmail('ada@example.com')));
    }

    #[Test]
    public function it_rejects_a_second_handler_for_the_same_message_type_spelled_differently(): void
    {
        $registry = new MessageHandlerRegistry();
        $registry->register(SendWelcomeEmail::class, static function (object $message): void {});

        $this->expectException(DuplicateMessageHandlerException::class);

        $registry->register('\\' . strtoupper(SendWelcomeEmail::class), static function (object $message): void {});
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function typesNoObjectCanHave(): iterable
    {
        yield 'an empty string' => [''];
        yield 'an unknown class' => ['Dirthara\\Queue\\Tests\\Fixtures\\DoesNotExist'];
        yield 'a scalar type' => ['string'];
    }

    #[Test]
    #[DataProvider('typesNoObjectCanHave')]
    public function it_rejects_a_type_no_object_can_have(string $message): void
    {
        $this->expectException(InvalidMessageTypeException::class);

        new MessageHandlerRegistry()->register($message, static function (object $message): void {});
    }

    #[Test]
    public function it_rejects_an_interface_because_no_message_has_one_as_its_exact_class(): void
    {
        try {
            new MessageHandlerRegistry()->register(CustomerMessage::class, static function (object $message): void {});
            self::fail('An interface was accepted as a message type.');
        } catch (InvalidMessageTypeException $exception) {
            self::assertStringContainsString('it is an interface', $exception->getMessage());
        }
    }

    #[Test]
    public function it_rejects_an_abstract_class_because_no_message_has_one_as_its_exact_class(): void
    {
        try {
            new MessageHandlerRegistry()->register(PaymentMessage::class, static function (object $message): void {});
            self::fail('An abstract class was accepted as a message type.');
        } catch (InvalidMessageTypeException $exception) {
            self::assertStringContainsString('it is an abstract class', $exception->getMessage());
        }
    }

    #[Test]
    public function it_does_not_provide_a_parent_handler_for_a_subclass(): void
    {
        $registry = new MessageHandlerRegistry();
        $registry->register(TakePayment::class, static function (object $message): void {});

        $this->expectException(MessageHandlerNotFoundException::class);

        $registry->handlerFor(new readonly class('PAY-1') extends PaymentMessage {});
    }

    #[Test]
    public function it_refuses_a_message_type_without_a_handler(): void
    {
        $registry = new MessageHandlerRegistry();
        $registry->register(GenerateInvoice::class, static function (object $message): void {});

        try {
            $registry->handlerFor(new SendWelcomeEmail('ada@example.com'));
            self::fail('A message type without a handler was accepted.');
        } catch (MessageHandlerNotFoundException $exception) {
            self::assertSame(['message' => SendWelcomeEmail::class], $exception->context);
        }
    }
}
