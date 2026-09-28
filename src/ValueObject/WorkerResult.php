<?php

declare(strict_types=1);

namespace Dirthara\Queue\ValueObject;

use Throwable;
use Dirthara\Queue\WorkerOutcome;

final readonly class WorkerResult
{
    private function __construct(
        public WorkerOutcome $outcome,
        public ?Throwable $failure = null,
    ) {}

    public static function idle(): self
    {
        return new self(WorkerOutcome::Idle);
    }

    public static function handled(): self
    {
        return new self(WorkerOutcome::Handled);
    }

    public static function failed(Throwable $failure): self
    {
        return new self(WorkerOutcome::Failed, $failure);
    }
}
