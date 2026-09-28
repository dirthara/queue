<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Fixtures;

use Throwable;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\Contract\RetryPolicy;

final class RecordingRetryPolicy implements RetryPolicy
{
    /**
     * @var list<array{delivery: Delivery, failure: Throwable}>
     */
    public private(set) array $asked = [];

    public function __construct(
        private readonly bool $retry,
    ) {}

    public function shouldRetry(Delivery $delivery, Throwable $failure): bool
    {
        $this->asked[] = ['delivery' => $delivery, 'failure' => $failure];

        return $this->retry;
    }
}
