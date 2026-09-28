<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

use Throwable;

interface RetryPolicy
{
    public function shouldRetry(Delivery $delivery, Throwable $failure): bool;
}
