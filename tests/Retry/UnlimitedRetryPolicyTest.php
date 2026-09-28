<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Retry;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Tests\Fixtures\Deliveries;
use Dirthara\Queue\Retry\UnlimitedRetryPolicy;

final class UnlimitedRetryPolicyTest extends TestCase
{
    #[Test]
    public function it_always_retries(): void
    {
        $policy = new UnlimitedRetryPolicy();
        $failure = new RuntimeException('failure');

        self::assertTrue($policy->shouldRetry(Deliveries::onAttempt(1), $failure));
        self::assertTrue($policy->shouldRetry(Deliveries::onAttempt(100), $failure));
    }
}
