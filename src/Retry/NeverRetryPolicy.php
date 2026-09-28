<?php

declare(strict_types=1);

namespace Dirthara\Queue\Retry;

use Throwable;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\Contract\RetryPolicy;

final readonly class NeverRetryPolicy implements RetryPolicy
{
    public function shouldRetry(Delivery $delivery, Throwable $failure): bool
    {
        return false;
    }
}
