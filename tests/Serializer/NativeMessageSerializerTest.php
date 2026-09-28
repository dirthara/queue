<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Serializer;

use Error;
use stdClass;
use ArrayObject;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Dirthara\Queue\QueuedMessage;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Tests\Fixtures\Maintenance;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Queue\Tests\Fixtures\GenerateInvoice;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmail;
use Dirthara\Queue\Serializer\NativeMessageSerializer;
use Dirthara\Queue\Exception\MessageSerializationException;

use function serialize;
use function set_error_handler;
use function restore_error_handler;

final class NativeMessageSerializerTest extends TestCase
{
    #[Test]
    public function it_records_the_exact_class_of_the_message_as_its_type(): void
    {
        $queued = new NativeMessageSerializer()->serialize(new SendWelcomeEmail('ada@example.com'));

        self::assertSame(SendWelcomeEmail::class, $queued->type);
        self::assertSame(serialize(new SendWelcomeEmail('ada@example.com')), $queued->payload);
    }

    #[Test]
    public function it_restores_a_serialized_message(): void
    {
        $serializer = new NativeMessageSerializer();

        $message = $serializer->deserialize($serializer->serialize(new SendWelcomeEmail('ada@example.com')));

        self::assertEquals(new SendWelcomeEmail('ada@example.com'), $message);
    }

    #[Test]
    public function it_restores_an_enum_case_as_the_same_case(): void
    {
        $serializer = new NativeMessageSerializer();

        $queued = $serializer->serialize(Maintenance::PurgeExpiredSessions);

        self::assertSame(Maintenance::class, $queued->type);
        self::assertSame(Maintenance::PurgeExpiredSessions, $serializer->deserialize($queued));
    }

    #[Test]
    public function it_restores_the_objects_a_message_holds(): void
    {
        $serializer = new NativeMessageSerializer();
        $message = new stdClass();
        $message->sentAt = new DateTimeImmutable('2026-09-28 12:00:00');

        $restored = $serializer->deserialize($serializer->serialize($message));

        self::assertEquals($message, $restored);
        self::assertNotSame($message, $restored);
    }

    /**
     * @return iterable<string, array{object}>
     */
    public static function unserializableMessages(): iterable
    {
        yield 'a closure' => [static function (): void {}];
        yield 'an anonymous class' => [new class {}];
    }

    #[Test]
    #[DataProvider('unserializableMessages')]
    public function it_refuses_a_message_that_cannot_be_serialized(object $message): void
    {
        try {
            new NativeMessageSerializer()->serialize($message);
            self::fail('A message that cannot be serialized was accepted.');
        } catch (MessageSerializationException $exception) {
            self::assertNotNull($exception->getPrevious());
            self::assertStringStartsWith('Unable to serialize a message of type', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedPayloads(): iterable
    {
        yield 'garbage' => ['not a serialized value'];
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
            new NativeMessageSerializer()->deserialize(new QueuedMessage(SendWelcomeEmail::class, $payload));
            self::fail('A malformed payload was accepted.');
        } catch (MessageSerializationException $exception) {
            self::assertSame(
                'Unable to deserialize a message of type "Dirthara\\Queue\\Tests\\Fixtures\\SendWelcomeEmail": the payload is malformed.',
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
            new NativeMessageSerializer()->deserialize(new QueuedMessage(SendWelcomeEmail::class, 'garbage'));
            self::fail('A malformed payload was accepted.');
        } catch (MessageSerializationException) {
            self::assertSame($handler, set_error_handler(null));
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function it_wraps_an_exception_thrown_while_restoring_an_object(): void
    {
        // ArrayObject rejects a storage value that is neither an array nor an object with an UnexpectedValueException.
        $payload = 'O:11:"ArrayObject":4:{i:0;i:0;i:1;i:5;i:2;a:0:{}i:3;N;}';

        try {
            new NativeMessageSerializer()->deserialize(new QueuedMessage(ArrayObject::class, $payload));
            self::fail('An object that failed to restore was accepted.');
        } catch (MessageSerializationException $exception) {
            self::assertNotNull($exception->getPrevious());
            self::assertStringEndsWith('the payload is malformed.', $exception->getMessage());
        }
    }

    #[Test]
    public function it_lets_an_error_thrown_while_restoring_an_object_through_unchanged(): void
    {
        $this->expectException(Error::class);

        new NativeMessageSerializer()->deserialize(new QueuedMessage(
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
            new NativeMessageSerializer()->deserialize(new QueuedMessage(SendWelcomeEmail::class, $payload));
            self::fail('A payload that is not an object was accepted.');
        } catch (MessageSerializationException $exception) {
            self::assertSame(['message' => SendWelcomeEmail::class, 'actual' => $actual], $exception->context);
        }
    }

    #[Test]
    public function it_refuses_a_payload_of_another_type_than_the_message_declares(): void
    {
        $payload = serialize(new GenerateInvoice('INV-1'));

        try {
            new NativeMessageSerializer()->deserialize(new QueuedMessage(SendWelcomeEmail::class, $payload));
            self::fail('A payload of another type was accepted.');
        } catch (MessageSerializationException $exception) {
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
            new NativeMessageSerializer()->deserialize(new QueuedMessage('App\\Removed', 'O:11:"App\\Removed":0:{}'));
            self::fail('A payload of a class that does not exist was accepted.');
        } catch (MessageSerializationException $exception) {
            self::assertSame(['message' => 'App\\Removed', 'actual' => '__PHP_Incomplete_Class'], $exception->context);
        }
    }

    #[Test]
    public function it_keeps_the_payload_out_of_the_exception(): void
    {
        $payload = serialize(new GenerateInvoice('secret-invoice-reference'));

        try {
            new NativeMessageSerializer()->deserialize(new QueuedMessage(SendWelcomeEmail::class, $payload . 'x'));
            self::fail('A malformed payload was accepted.');
        } catch (MessageSerializationException $exception) {
            self::assertStringNotContainsString('secret-invoice-reference', $exception->getMessage());
        }
    }
}
