<?php

declare(strict_types=1);

namespace Dirthara\Queue\Backoff;

use Throwable;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\Contract\BackoffPolicy;

use function min;
use function intdiv;

final readonly class ExponentialBackoffPolicy implements BackoffPolicy
{
    public function __construct(
        public Duration $initialDuration,
        public Duration $maximumDuration,
    ) {}

    public function delay(Delivery $delivery, Throwable $failure): Duration
    {
        $maximum = $this->maximumDuration->milliseconds;
        $delay = min($this->initialDuration->milliseconds, $maximum);

        for ($attempt = 1; $attempt < $delivery->attempt && $delay > 0 && $delay < $maximum; $attempt++) {
            $delay = $delay > intdiv($maximum, num2: 2) ? $maximum : $delay * 2;
        }

        return Duration::milliseconds($delay);
    }
}
