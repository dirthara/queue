<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Backoff;

use Throwable;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use Dirthara\Queue\Contract\Delivery;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\Tests\Fixtures\Deliveries;
use Dirthara\Queue\ValueObject\QueuedMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Queue\Backoff\ExponentialBackoffPolicy;

use const PHP_INT_MAX;

final class ExponentialBackoffPolicyTest extends TestCase
{
    #[Test]
    public function it_exposes_its_durations(): void
    {
        $initial = Duration::seconds(1);
        $maximum = Duration::minutes(1);
        $policy = new ExponentialBackoffPolicy($initial, $maximum);

        self::assertSame($initial, $policy->initialDuration);
        self::assertSame($maximum, $policy->maximumDuration);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function delaysPerAttempt(): iterable
    {
        yield 'the first attempt' => [1, 1000];
        yield 'the second attempt' => [2, 2000];
        yield 'the third attempt' => [3, 4000];
        yield 'the sixth attempt' => [6, 32_000];
        yield 'the seventh attempt, capped' => [7, 60_000];
        yield 'a much later attempt, capped' => [40, 60_000];
    }

    #[Test]
    #[DataProvider('delaysPerAttempt')]
    public function it_doubles_the_delay_with_each_attempt_up_to_the_maximum(int $attempt, int $milliseconds): void
    {
        $policy = new ExponentialBackoffPolicy(Duration::seconds(1), Duration::minutes(1));

        self::assertSame(
            $milliseconds,
            $policy->delay(Deliveries::onAttempt($attempt), new RuntimeException('failure'))->milliseconds,
        );
    }

    #[Test]
    public function it_caps_the_first_delay_at_the_maximum(): void
    {
        $policy = new ExponentialBackoffPolicy(Duration::minutes(5), Duration::minutes(1));

        self::assertSame(
            60_000,
            $policy->delay(Deliveries::onAttempt(1), new RuntimeException('failure'))->milliseconds,
        );
    }

    #[Test]
    public function it_never_delays_when_the_initial_delay_is_zero(): void
    {
        $policy = new ExponentialBackoffPolicy(Duration::milliseconds(0), Duration::minutes(1));

        self::assertSame(
            0,
            $policy->delay(self::deliveryOnAttempt(PHP_INT_MAX), new RuntimeException('failure'))->milliseconds,
        );
    }

    #[Test]
    public function it_does_not_overflow_on_a_very_late_attempt_without_a_practical_maximum(): void
    {
        $policy = new ExponentialBackoffPolicy(Duration::seconds(1), Duration::milliseconds(PHP_INT_MAX));

        self::assertSame(
            PHP_INT_MAX,
            $policy->delay(self::deliveryOnAttempt(PHP_INT_MAX), new RuntimeException('failure'))->milliseconds,
        );
    }

    #[Test]
    public function it_doubles_up_to_the_largest_delay_below_an_odd_maximum(): void
    {
        $policy = new ExponentialBackoffPolicy(Duration::milliseconds(3), Duration::milliseconds(13));

        self::assertSame(12, $policy->delay(self::deliveryOnAttempt(3), new RuntimeException('failure'))->milliseconds);
        self::assertSame(13, $policy->delay(self::deliveryOnAttempt(4), new RuntimeException('failure'))->milliseconds);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function attemptsBeforeTheFirst(): iterable
    {
        yield 'zero' => [0];
        yield 'a negative attempt' => [-3];
    }

    #[Test]
    #[DataProvider('attemptsBeforeTheFirst')]
    public function it_uses_the_initial_delay_for_an_attempt_before_the_first(int $attempt): void
    {
        $policy = new ExponentialBackoffPolicy(Duration::seconds(1), Duration::minutes(1));

        self::assertSame(
            1000,
            $policy->delay(self::deliveryOnAttempt($attempt), new RuntimeException('failure'))->milliseconds,
        );
    }

    private static function deliveryOnAttempt(int $attempt): Delivery
    {
        return new readonly class($attempt) implements Delivery {
            public QueuedMessage $message;

            public function __construct(
                public int $attempt,
            ) {
                $this->message = new QueuedMessage('type', 'payload');
            }

            public function acknowledge(): void {}

            public function release(?Duration $duration = null): void {}

            public function fail(?Throwable $throwable = null): void {}
        };
    }
}
