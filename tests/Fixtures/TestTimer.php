<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Fixtures;

use Dirthara\Queue\ValueObject\Duration;

final class TestTimer
{
    public private(set) int $nanoseconds = 0;

    public function now(): int
    {
        return $this->nanoseconds;
    }

    public function advance(Duration $duration): void
    {
        $this->nanoseconds += $duration->milliseconds * 1_000_000;
    }
}
