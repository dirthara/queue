<?php

declare(strict_types=1);

namespace Dirthara\Queue\Backoff;

use Throwable;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\Contract\BackoffPolicy;

final readonly class FixedBackoffPolicy implements BackoffPolicy
{
    public function __construct(
        public Duration $duration,
    ) {}

    public function delay(Delivery $delivery, Throwable $failure): Duration
    {
        return $this->duration;
    }
}
