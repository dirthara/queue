<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\ValueObject;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\ValueObject\WorkerLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Queue\Exception\InvalidWorkerLimitsException;

final class WorkerLimitsTest extends TestCase
{
    #[Test]
    public function it_is_unlimited_by_default(): void
    {
        $limits = new WorkerLimits();

        self::assertNull($limits->maxMessages);
        self::assertNull($limits->maxRuntime);
    }

    #[Test]
    public function it_carries_a_message_and_a_runtime_limit(): void
    {
        $runtime = Duration::hours(1);

        $limits = new WorkerLimits(maxMessages: 1000, maxRuntime: $runtime);

        self::assertSame(1000, $limits->maxMessages);
        self::assertSame($runtime, $limits->maxRuntime);
    }

    #[Test]
    public function it_accepts_the_smallest_limits(): void
    {
        $limits = new WorkerLimits(maxMessages: 1, maxRuntime: Duration::milliseconds(1));

        self::assertSame(1, $limits->maxMessages);
        self::assertSame(1, $limits->maxRuntime?->milliseconds);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function tooFewMessages(): iterable
    {
        yield 'zero' => [0];
        yield 'a negative number' => [-1];
    }

    #[Test]
    #[DataProvider('tooFewMessages')]
    public function it_refuses_fewer_than_one_message(int $maxMessages): void
    {
        try {
            new WorkerLimits(maxMessages: $maxMessages);
            self::fail('Fewer than one message was accepted.');
        } catch (InvalidWorkerLimitsException $exception) {
            self::assertSame(['maxMessages' => $maxMessages], $exception->context);
        }
    }

    #[Test]
    public function it_refuses_a_runtime_of_zero(): void
    {
        try {
            new WorkerLimits(maxRuntime: Duration::seconds(0));
            self::fail('A runtime of zero was accepted.');
        } catch (InvalidWorkerLimitsException $exception) {
            self::assertSame(['maxRuntime' => 0], $exception->context);
        }
    }
}
