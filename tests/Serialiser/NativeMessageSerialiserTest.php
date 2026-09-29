<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Serialiser;

use Error;
use stdClass;
use ArrayObject;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Queue\Tests\Fixtures\Maintenance;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Queue\Tests\Fixtures\GenerateInvoice;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmail;
use Dirthara\Queue\Serialiser\NativeMessageSerialiser;
use Dirthara\Queue\Exception\MessageSerialisationException;

use function serialize;
use function set_error_handler;
use function restore_error_handler;

final class NativeMessageSerialiserTest extends TestCase
{
    #[Test]
    public function it_records_the_exact_class_of_the_message_as_its_type(): void
    {
        $queued = new NativeMessageSerialiser()->serialise(new SendWelcomeEmail('ada@example.com'));

        self::assertSame(SendWelcomeEmail::class, $queued->type);
        self::assertSame(serialize(new SendWelcomeEmail('ada@example.com')), $queued->payload);
    }

    #[Test]
    public function it_restores_a_serialised_message(): void
    {
        $serialiser = new NativeMessageSerialiser();

        $message = $serialiser->deserialise($serialiser->serialise(new SendWelcomeEmail('ada@example.com')));

        self::assertEquals(new SendWelcomeEmail('ada@example.com'), $message);
    }

    #[Test]
    public function it_restores_an_enum_case_as_the_same_case(): void
    {
        $serialiser = new NativeMessageSerialiser();

        $queued = $serialiser->serialise(Maintenance::PurgeExpiredSessions);

        self::assertSame(Maintenance::class, $queued->type);
        self::assertSame(Maintenance::PurgeExpiredSessions, $serialiser->deserialise($queued));
    }

    #[Test]
    public function it_restores_the_objects_a_message_holds(): void
    {
        $serialiser = new NativeMessageSerialiser();
        $message = new stdClass();
        $message->sentAt = new DateTimeImmutable('2026-09-28 12:00:00');

        $restored = $serialiser->deserialise($serialiser->serialise($message));

        self::assertEquals($message, $restored);
        self::assertNotSame($message, $restored);
    }

    /**
     * @return iterable<string, array{object}>
     */
    public static function unserialisableMessages(): iterable
    {
        yield 'a closure' => [static function (): void {}];
        yield 'an anonymous class' => [new class {}];
    }

    #[Test]
    #[DataProvider('unserialisableMessages')]
    public function it_refuses_a_message_that_cannot_be_serialised(object $message): void
    {
        try {
            new NativeMessageSerialiser()->serialise($message);
            self::fail('A message that cannot be serialised was accepted.');
        } catch (MessageSerialisationException $exception) {
            self::assertNotNull($exception->getPrevious());
            self::assertStringStartsWith('Unable to serialise a message of type', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedPayloads(): iterable
    {
        yield 'garbage' => ['not a serialised value'];
        yield 'an empty payload' => [''];
        yield 'a truncated payload' => [
            'O:48:"Dirthara\\Queue\\Tests\\Fixtures\\SendWelcomeEmail":1:{s:5:"email";s:15:"ada@',
        ];
        yield 'a valid payload followed by extra data' => [serialize(new SendWelcomeEmail('ada@example.com')) . 'x'];
    }

    #[Test]
    #[DataProvider('malformedPayloads')]
    public function it_refuses_a_malformed_payload(string $payload): void
    {
        try {
            new NativeMessageSerialiser()->deserialise(new QueuedMessage(SendWelcomeEmail::class, $payload));
            self::fail('A malformed payload was accepted.');
        } catch (MessageSerialisationException $exception) {
            self::assertSame(
                'Unable to deserialise a message of type "Dirthara\\Queue\\Tests\\Fixtures\\SendWelcomeEmail": the payload is malformed.',
                $exception->getMessage(),
            );
        }
    }

    #[Test]
    public function it_restores_the_previous_error_handler_after_a_malformed_payload(): void
    {
        $handler = static fn(): bool => true;
        set_error_handler($handler);

        try {
            new NativeMessageSerialiser()->deserialise(new QueuedMessage(SendWelcomeEmail::class, 'garbage'));
            self::fail('A malformed payload was accepted.');
        } catch (MessageSerialisationException) {
            self::assertSame($handler, set_error_handler(null));
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function it_wraps_an_exception_thrown_while_restoring_an_object(): void
    {
        $payload = 'O:11:"ArrayObject":4:{i:0;i:0;i:1;i:5;i:2;a:0:{}i:3;N;}';

        try {
            new NativeMessageSerialiser()->deserialise(new QueuedMessage(ArrayObject::class, $payload));
            self::fail('An object that failed to restore was accepted.');
        } catch (MessageSerialisationException $exception) {
            self::assertNotNull($exception->getPrevious());
            self::assertStringEndsWith('the payload is malformed.', $exception->getMessage());
        }
    }

    #[Test]
    public function it_lets_an_error_thrown_while_restoring_an_object_through_unchanged(): void
    {
        $this->expectException(Error::class);

        new NativeMessageSerialiser()->deserialise(new QueuedMessage(
            DateTimeImmutable::class,
            'O:17:"DateTimeImmutable":1:{s:4:"date";i:1;}',
        ));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valuesThatAreNotObjects(): iterable
    {
        yield 'false' => [serialize(false), 'bool'];
        yield 'an int' => [serialize(42), 'int'];
        yield 'a string' => [serialize('ada@example.com'), 'string'];
        yield 'an array' => [serialize(['email' => 'ada@example.com']), 'array'];
        yield 'null' => [serialize(null), 'null'];
    }

    #[Test]
    #[DataProvider('valuesThatAreNotObjects')]
    public function it_refuses_a_payload_that_is_not_an_object(string $payload, string $actual): void
    {
        try {
            new NativeMessageSerialiser()->deserialise(new QueuedMessage(SendWelcomeEmail::class, $payload));
            self::fail('A payload that is not an object was accepted.');
        } catch (MessageSerialisationException $exception) {
            self::assertSame(['message' => SendWelcomeEmail::class, 'actual' => $actual], $exception->context);
        }
    }

    #[Test]
    public function it_refuses_a_payload_of_another_type_than_the_message_declares(): void
    {
        $payload = serialize(new GenerateInvoice('INV-1'));

        try {
            new NativeMessageSerialiser()->deserialise(new QueuedMessage(SendWelcomeEmail::class, $payload));
            self::fail('A payload of another type was accepted.');
        } catch (MessageSerialisationException $exception) {
            self::assertSame(
                ['message' => SendWelcomeEmail::class, 'actual' => GenerateInvoice::class],
                $exception->context,
            );
        }
    }

    #[Test]
    public function it_refuses_a_payload_whose_class_does_not_exist(): void
    {
        try {
            new NativeMessageSerialiser()->deserialise(new QueuedMessage('App\\Removed', 'O:11:"App\\Removed":0:{}'));
            self::fail('A payload of a class that does not exist was accepted.');
        } catch (MessageSerialisationException $exception) {
            self::assertSame(['message' => 'App\\Removed', 'actual' => '__PHP_Incomplete_Class'], $exception->context);
        }
    }

    #[Test]
    public function it_keeps_the_payload_out_of_the_exception(): void
    {
        $payload = serialize(new GenerateInvoice('secret-invoice-reference'));

        try {
            new NativeMessageSerialiser()->deserialise(new QueuedMessage(SendWelcomeEmail::class, $payload . 'x'));
            self::fail('A malformed payload was accepted.');
        } catch (MessageSerialisationException $exception) {
            self::assertStringNotContainsString('secret-invoice-reference', $exception->getMessage());
        }
    }
}
