<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

use Throwable;
use Dirthara\Queue\ValueObject\Duration;

interface BackoffPolicy
{
    public function delay(Delivery $delivery, Throwable $failure): Duration;
}
