<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Backoff;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Backoff\NoBackoffPolicy;
use Dirthara\Queue\Tests\Fixtures\Deliveries;

final class NoBackoffPolicyTest extends TestCase
{
    #[Test]
    public function it_never_delays_a_retry(): void
    {
        $policy = new NoBackoffPolicy();
        $failure = new RuntimeException('failure');

        self::assertSame(0, $policy->delay(Deliveries::onAttempt(1), $failure)->milliseconds);
        self::assertSame(0, $policy->delay(Deliveries::onAttempt(10), $failure)->milliseconds);
    }
}
