<?php

declare(strict_types=1);

namespace Dirthara\Queue\ValueObject;

use Dirthara\Queue\Contract\RetryPolicy;
use Dirthara\Queue\Contract\BackoffPolicy;

final readonly class    MessageExecutionPolicy
{
    public function __construct(
        public RetryPolicy $retry,
        public BackoffPolicy $backoff,
    ) {}
}
