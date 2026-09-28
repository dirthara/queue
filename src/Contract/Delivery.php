<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

use Throwable;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\ValueObject\QueuedMessage;

interface Delivery
{
    public QueuedMessage $message { get; }

    public int $attempt { get; }

    public function acknowledge(): void;

    public function release(?Duration $duration = null): void;

    public function fail(?Throwable $throwable = null): void;
}
