<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Fixtures;

use DateTimeImmutable;

final class TestClock
{
    public private(set) DateTimeImmutable $now;

    public function __construct(string $now = '2026-09-29 12:00:00.000')
    {
        $this->now = new DateTimeImmutable($now);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
