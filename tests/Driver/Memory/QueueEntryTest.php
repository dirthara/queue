<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Driver\Memory;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Driver\Memory\QueueEntry;
use Dirthara\Queue\ValueObject\QueuedMessage;

final class QueueEntryTest extends TestCase
{
    #[Test]
    public function it_starts_as_a_first_attempt_that_is_available_straight_away(): void
    {
        $message = new QueuedMessage('type', 'payload');
        $entry = new QueueEntry($message);

        self::assertSame($message, $entry->message);
        self::assertSame(1, $entry->attempt);
        self::assertNull($entry->availableAt);
        self::assertTrue($entry->isAvailableAt(new DateTimeImmutable('2026-09-29 12:00:00')));
    }

    #[Test]
    public function it_retries_as_the_next_attempt(): void
    {
        $message = new QueuedMessage('type', 'payload');
        $availableAt = new DateTimeImmutable('2026-09-29 12:00:30');

        $retry = new QueueEntry($message, 2)->retry($availableAt);

        self::assertSame($message, $retry->message);
        self::assertSame(3, $retry->attempt);
        self::assertSame($availableAt, $retry->availableAt);
    }

    #[Test]
    public function it_retries_without_a_delay_by_default(): void
    {
        $delayed = new QueueEntry(
            new QueuedMessage('type', 'payload'),
            1,
            new DateTimeImmutable('2026-09-29 12:00:30'),
        );

        self::assertNull($delayed->retry()->availableAt);
    }

    #[Test]
    public function it_is_available_from_the_moment_its_delay_ends(): void
    {
        $entry = new QueueEntry(
            new QueuedMessage('type', 'payload'),
            availableAt: new DateTimeImmutable('2026-09-29 12:00:30.000'),
        );

        self::assertFalse($entry->isAvailableAt(new DateTimeImmutable('2026-09-29 12:00:29.999')));
        self::assertTrue($entry->isAvailableAt(new DateTimeImmutable('2026-09-29 12:00:30.000')));
        self::assertTrue($entry->isAvailableAt(new DateTimeImmutable('2026-09-29 12:00:31.000')));
    }
}
