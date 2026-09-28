<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

use Throwable;
use Dirthara\Queue\QueuedMessage;
use Dirthara\Queue\ValueObject\Duration;

interface Delivery
{
    public QueuedMessage $message { get; }

    public int $attempt { get; }

    public function acknowledge(): void;

    public function release(?Duration $duration = null): void;

    public function fail(?Throwable $throwable = null): void;
}
