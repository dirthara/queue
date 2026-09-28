<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Config;

use stdClass;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Config\QueueConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Queue\Exception\InvalidQueueConfigurationException;

use function print_r;

final class QueueConfigurationTest extends TestCase
{
    #[Test]
    public function it_carries_the_driver_name(): void
    {
        self::assertSame('memory', new QueueConfiguration('memory')->driver);
    }

    #[Test]
    public function it_knows_which_options_it_has(): void
    {
        $configuration = new QueueConfiguration('redis', ['host' => 'localhost', 'password' => null]);

        self::assertTrue($configuration->has('host'));
        self::assertTrue($configuration->has('password'));
        self::assertFalse($configuration->has('port'));
    }

    #[Test]
    public function it_reads_options_of_each_type(): void
    {
        $configuration = new QueueConfiguration('redis', [
            'host' => 'localhost',
            'port' => 6379,
            'tls' => false,
        ]);

        self::assertSame('localhost', $configuration->string('host'));
        self::assertSame(6379, $configuration->int('port'));
        self::assertFalse($configuration->bool('tls'));
    }

    #[Test]
    public function it_prefers_a_configured_option_over_the_default(): void
    {
        $configuration = new QueueConfiguration('redis', [
            'host' => 'redis.internal',
            'port' => 6380,
            'tls' => true,
        ]);

        self::assertSame('redis.internal', $configuration->string('host', 'localhost'));
        self::assertSame(6380, $configuration->int('port', 6379));
        self::assertTrue($configuration->bool('tls', false));
    }

    #[Test]
    public function it_falls_back_to_the_default_for_a_missing_option(): void
    {
        $configuration = new QueueConfiguration('redis');

        self::assertSame('localhost', $configuration->string('host', 'localhost'));
        self::assertSame(6379, $configuration->int('port', 6379));
        self::assertFalse($configuration->bool('tls', false));
    }

    #[Test]
    public function it_returns_falsy_values_instead_of_the_default(): void
    {
        $configuration = new QueueConfiguration('redis', ['prefix' => '', 'database' => 0, 'tls' => false]);

        self::assertSame('', $configuration->string('prefix', 'queue'));
        self::assertSame(0, $configuration->int('database', 3));
        self::assertFalse($configuration->bool('tls', true));
    }

    /**
     * @return iterable<string, array{callable(QueueConfiguration): mixed}>
     */
    public static function readers(): iterable
    {
        yield 'a string' => [static fn(QueueConfiguration $configuration): string => $configuration->string('option')];
        yield 'an int' => [static fn(QueueConfiguration $configuration): int => $configuration->int('option')];
        yield 'a bool' => [static fn(QueueConfiguration $configuration): bool => $configuration->bool('option')];
    }

    /**
     * @param callable(QueueConfiguration): mixed $read
     */
    #[Test]
    #[DataProvider('readers')]
    public function it_refuses_a_missing_option_without_a_default(callable $read): void
    {
        try {
            $read(new QueueConfiguration('redis'));
            self::fail('A missing option without a default was accepted.');
        } catch (InvalidQueueConfigurationException $exception) {
            self::assertSame(['driver' => 'redis', 'option' => 'option'], $exception->context);
        }
    }

    /**
     * @return iterable<string, array{callable(QueueConfiguration): mixed, mixed, string, string}>
     */
    public static function mistypedOptions(): iterable
    {
        $string = static fn(QueueConfiguration $configuration): string => $configuration->string('option', 'default');
        $int = static fn(QueueConfiguration $configuration): int => $configuration->int('option', 1);
        $bool = static fn(QueueConfiguration $configuration): bool => $configuration->bool('option', true);

        yield 'an int read as a string' => [$string, 6379, 'string', 'int'];
        yield 'null read as a string' => [$string, null, 'string', 'null'];
        yield 'a numeric string read as an int' => [$int, '6379', 'int', 'string'];
        yield 'a float read as an int' => [$int, 1.5, 'int', 'float'];
        yield 'null read as an int' => [$int, null, 'int', 'null'];
        yield 'a string read as a bool' => [$bool, 'true', 'bool', 'string'];
        yield 'an int read as a bool' => [$bool, 1, 'bool', 'int'];
        yield 'an object read as a bool' => [$bool, new stdClass(), 'bool', 'stdClass'];
    }

    /**
     * @param callable(QueueConfiguration): mixed $read
     */
    #[Test]
    #[DataProvider('mistypedOptions')]
    public function it_refuses_an_option_of_the_wrong_type_even_with_a_default(
        callable $read,
        mixed $value,
        string $expected,
        string $actual,
    ): void {
        try {
            $read(new QueueConfiguration('redis', ['option' => $value]));
            self::fail('An option of the wrong type was accepted.');
        } catch (InvalidQueueConfigurationException $exception) {
            self::assertSame(
                ['driver' => 'redis', 'option' => 'option', 'expected' => $expected, 'actual' => $actual],
                $exception->context,
            );
        }
    }

    #[Test]
    public function it_keeps_a_mistyped_value_out_of_the_exception(): void
    {
        try {
            new QueueConfiguration('redis', ['password' => ['s3cr3t']])->string('password');
            self::fail('An option of the wrong type was accepted.');
        } catch (InvalidQueueConfigurationException $exception) {
            self::assertStringNotContainsString('s3cr3t', $exception->getMessage());
            self::assertStringNotContainsString('s3cr3t', print_r($exception->context, return: true));
        }
    }
}
