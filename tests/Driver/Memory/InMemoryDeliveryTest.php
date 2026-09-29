<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Driver\Memory;

use Closure;
use Throwable;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\Driver\Memory\QueueEntry;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Queue\Driver\Memory\InMemoryDelivery;
use Dirthara\Queue\Exception\DeliveryAlreadySettledException;

use function count;

final class InMemoryDeliveryTest extends TestCase
{
    #[Test]
    public function it_describes_its_entry(): void
    {
        $message = new QueuedMessage('type', 'payload');

        $delivery = new InMemoryDelivery(new QueueEntry($message, 3), self::ignore(), self::ignore());

        self::assertSame($message, $delivery->message);
        self::assertSame(3, $delivery->attempt);
    }

    #[Test]
    public function it_passes_its_entry_and_delay_to_the_release(): void
    {
        $entry = new QueueEntry(new QueuedMessage('type', 'payload'));
        $delay = Duration::seconds(5);
        $released = [];

        $delivery = new InMemoryDelivery(
            $entry,
            static function (QueueEntry $entry, ?Duration $delay) use (&$released): void {
                $released[] = [$entry, $delay];
            },
            self::ignore(),
        );

        $delivery->release($delay);

        self::assertSame([[$entry, $delay]], $released);
    }

    #[Test]
    public function it_passes_its_entry_and_failure_to_the_failure(): void
    {
        $entry = new QueueEntry(new QueuedMessage('type', 'payload'));
        $failure = new RuntimeException('The handler failed.');
        $failed = [];

        $delivery = new InMemoryDelivery($entry, self::ignore(), static function (
            QueueEntry $entry,
            ?Throwable $failure,
        ) use (&$failed): void {
            $failed[] = [$entry, $failure];
        });

        $delivery->fail($failure);

        self::assertSame([[$entry, $failure]], $failed);
    }

    #[Test]
    public function it_is_not_released_when_releasing_throws_and_can_be_released_again(): void
    {
        $problem = new RuntimeException('The queue could not take the message back.');
        $calls = [];

        $delivery = new InMemoryDelivery(
            new QueueEntry(new QueuedMessage('type', 'payload')),
            static function (QueueEntry $entry, ?Duration $delay) use (&$calls, $problem): void {
                $calls[] = $entry;

                if (count($calls) === 1) {
                    throw $problem;
                }
            },
            self::ignore(),
        );

        try {
            $delivery->release();
            self::fail('The release problem did not escape.');
        } catch (RuntimeException $exception) {
            self::assertSame($problem, $exception);
        }

        $delivery->release();

        self::assertCount(2, $calls);
        self::assertSettledAs('released', $delivery);
    }

    #[Test]
    public function it_is_not_failed_when_failing_throws_and_can_be_failed_again(): void
    {
        $problem = new RuntimeException('The failed message could not be recorded.');
        $calls = [];

        $delivery = new InMemoryDelivery(
            new QueueEntry(new QueuedMessage('type', 'payload')),
            self::ignore(),
            static function (QueueEntry $entry, ?Throwable $failure) use (&$calls, $problem): void {
                $calls[] = $entry;

                if (count($calls) === 1) {
                    throw $problem;
                }
            },
        );

        try {
            $delivery->fail();
            self::fail('The failure problem did not escape.');
        } catch (RuntimeException $exception) {
            self::assertSame($problem, $exception);
        }

        $delivery->fail();

        self::assertCount(2, $calls);
        self::assertSettledAs('failed', $delivery);
    }

    #[Test]
    public function it_can_be_settled_another_way_after_a_settlement_threw(): void
    {
        $delivery = new InMemoryDelivery(
            new QueueEntry(new QueuedMessage('type', 'payload')),
            self::throwing(),
            self::throwing(),
        );

        foreach ([$delivery->release(...), $delivery->fail(...)] as $settle) {
            try {
                $settle();
                self::fail('The settlement problem did not escape.');
            } catch (RuntimeException $exception) {
                self::assertSame('The settlement failed.', $exception->getMessage());
            }
        }

        $delivery->acknowledge();

        self::assertSettledAs('acknowledged', $delivery);
    }

    #[Test]
    public function it_is_settled_once_it_is_acknowledged(): void
    {
        $delivery = new InMemoryDelivery(
            new QueueEntry(new QueuedMessage('type', 'payload')),
            self::ignore(),
            self::ignore(),
        );

        $delivery->acknowledge();

        self::assertSettledAs('acknowledged', $delivery);
    }

    private static function assertSettledAs(string $settled, InMemoryDelivery $delivery): void
    {
        foreach ([$delivery->acknowledge(...), $delivery->release(...), $delivery->fail(...)] as $settle) {
            try {
                $settle();
                self::fail('A settled delivery was settled again.');
            } catch (DeliveryAlreadySettledException $exception) {
                self::assertSame(['message' => 'type', 'settled' => $settled], $exception->context);
            }
        }
    }

    /**
     * @return Closure(QueueEntry, mixed): void
     */
    private static function ignore(): Closure
    {
        return static function (QueueEntry $entry, mixed $detail): void {};
    }

    /**
     * @return Closure(QueueEntry, mixed): void
     */
    private static function throwing(): Closure
    {
        return static function (QueueEntry $entry, mixed $detail): void {
            throw new RuntimeException('The settlement failed.');
        };
    }
}
