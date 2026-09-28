<?php

declare(strict_types=1);

namespace Dirthara\Queue\Backoff;

use Throwable;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\Contract\BackoffPolicy;

final readonly class NoBackoffPolicy implements BackoffPolicy
{
    public function delay(Delivery $delivery, Throwable $failure): Duration
    {
        return Duration::milliseconds(0);
    }
}
