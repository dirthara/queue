<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests;

use PHPUnit\Framework\TestCase;
use Dirthara\Queue\WorkerOutcome;
use PHPUnit\Framework\Attributes\Test;

use function array_map;

final class WorkerOutcomeTest extends TestCase
{
    #[Test]
    public function it_has_a_stable_value_for_every_outcome(): void
    {
        self::assertSame(
            ['idle', 'handled', 'released', 'failed'],
            array_map(static fn(WorkerOutcome $outcome): string => $outcome->value, WorkerOutcome::cases()),
        );
    }

    #[Test]
    public function it_restores_an_outcome_from_its_value(): void
    {
        self::assertSame(WorkerOutcome::Released, WorkerOutcome::from('released'));
        self::assertNull(WorkerOutcome::tryFrom('retried'));
    }
}
