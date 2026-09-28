<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Retry;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Retry\NeverRetryPolicy;
use Dirthara\Queue\Tests\Fixtures\Deliveries;

final class NeverRetryPolicyTest extends TestCase
{
    #[Test]
    public function it_never_retries(): void
    {
        $policy = new NeverRetryPolicy();
        $failure = new RuntimeException('failure');

        self::assertFalse($policy->shouldRetry(Deliveries::onAttempt(1), $failure));
        self::assertFalse($policy->shouldRetry(Deliveries::onAttempt(10), $failure));
    }
}
