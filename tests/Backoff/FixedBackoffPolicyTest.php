<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Backoff;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\Tests\Fixtures\Deliveries;
use Dirthara\Queue\Backoff\FixedBackoffPolicy;

final class FixedBackoffPolicyTest extends TestCase
{
    #[Test]
    public function it_delays_every_retry_by_the_same_duration(): void
    {
        $duration = Duration::seconds(30);
        $policy = new FixedBackoffPolicy($duration);
        $failure = new RuntimeException('failure');

        self::assertSame($duration, $policy->duration);
        self::assertSame($duration, $policy->delay(Deliveries::onAttempt(1), $failure));
        self::assertSame($duration, $policy->delay(Deliveries::onAttempt(10), $failure));
    }
}
