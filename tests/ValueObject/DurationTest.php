<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\ValueObject;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\ValueObject\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Queue\Exception\InvalidDurationException;

use function intdiv;

use const PHP_INT_MAX;

final class DurationTest extends TestCase
{
    #[Test]
    public function it_converts_each_unit_to_milliseconds(): void
    {
        self::assertSame(1500, Duration::milliseconds(1500)->milliseconds);
        self::assertSame(30_000, Duration::seconds(30)->milliseconds);
        self::assertSame(300_000, Duration::minutes(5)->milliseconds);
        self::assertSame(7_200_000, Duration::hours(2)->milliseconds);
    }

    #[Test]
    public function it_allows_a_duration_of_zero_in_every_unit(): void
    {
        self::assertSame(0, Duration::milliseconds(0)->milliseconds);
        self::assertSame(0, Duration::seconds(0)->milliseconds);
        self::assertSame(0, Duration::minutes(0)->milliseconds);
        self::assertSame(0, Duration::hours(0)->milliseconds);
    }

    #[Test]
    public function it_allows_the_longest_duration_that_fits_in_each_unit(): void
    {
        self::assertSame(PHP_INT_MAX, Duration::milliseconds(PHP_INT_MAX)->milliseconds);
        self::assertSame(
            intdiv(PHP_INT_MAX, num2: 1000) * 1000,
            Duration::seconds(intdiv(PHP_INT_MAX, num2: 1000))->milliseconds,
        );
        self::assertSame(
            intdiv(PHP_INT_MAX, num2: 60_000) * 60_000,
            Duration::minutes(intdiv(PHP_INT_MAX, num2: 60_000))->milliseconds,
        );
        self::assertSame(
            intdiv(PHP_INT_MAX, num2: 3_600_000) * 3_600_000,
            Duration::hours(intdiv(PHP_INT_MAX, num2: 3_600_000))->milliseconds,
        );
    }

    /**
     * @return iterable<string, array{callable(int): Duration, string}>
     */
    public static function units(): iterable
    {
        yield 'milliseconds' => [Duration::milliseconds(...), 'milliseconds'];
        yield 'seconds' => [Duration::seconds(...), 'seconds'];
        yield 'minutes' => [Duration::minutes(...), 'minutes'];
        yield 'hours' => [Duration::hours(...), 'hours'];
    }

    /**
     * @param callable(int): Duration $create
     */
    #[Test]
    #[DataProvider('units')]
    public function it_refuses_a_negative_duration(callable $create, string $unit): void
    {
        try {
            $create(-1);
            self::fail('A negative duration was accepted.');
        } catch (InvalidDurationException $exception) {
            self::assertSame(['amount' => -1, 'unit' => $unit], $exception->context);
            self::assertStringContainsString('cannot be negative', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{callable(int): Duration, int, string}>
     */
    public static function tooLong(): iterable
    {
        yield 'seconds' => [Duration::seconds(...), intdiv(PHP_INT_MAX, num2: 1000) + 1, 'seconds'];
        yield 'minutes' => [Duration::minutes(...), intdiv(PHP_INT_MAX, num2: 60_000) + 1, 'minutes'];
        yield 'hours' => [Duration::hours(...), intdiv(PHP_INT_MAX, num2: 3_600_000) + 1, 'hours'];
        yield 'the largest number of hours' => [Duration::hours(...), PHP_INT_MAX, 'hours'];
    }

    /**
     * @param callable(int): Duration $create
     */
    #[Test]
    #[DataProvider('tooLong')]
    public function it_refuses_a_duration_that_does_not_fit_in_milliseconds(
        callable $create,
        int $amount,
        string $unit,
    ): void {
        try {
            $create($amount);
            self::fail('A duration that does not fit in milliseconds was accepted.');
        } catch (InvalidDurationException $exception) {
            self::assertSame(['amount' => $amount, 'unit' => $unit], $exception->context);
            self::assertStringContainsString('does not fit', $exception->getMessage());
        }
    }
}
