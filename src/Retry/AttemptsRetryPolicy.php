<?php

declare(strict_types=1);

namespace Dirthara\Queue\Retry;

use Throwable;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\Contract\RetryPolicy;
use Dirthara\Queue\Exception\InvalidRetryPolicyException;

final readonly class AttemptsRetryPolicy implements RetryPolicy
{
    /**
     * @throws InvalidRetryPolicyException
     */
    public function __construct(
        public int $maxAttempts = 3,
    ) {
        if ($maxAttempts < 1) {
            throw InvalidRetryPolicyException::tooFewAttempts($maxAttempts);
        }
    }

    public function shouldRetry(Delivery $delivery, Throwable $failure): bool
    {
        return $delivery->attempt < $this->maxAttempts;
    }
}
