<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\ValueObject;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\Retry\AttemptsRetryPolicy;
use Dirthara\Queue\Backoff\FixedBackoffPolicy;
use Dirthara\Queue\ValueObject\MessageExecutionPolicy;

final class MessageExecutionPolicyTest extends TestCase
{
    #[Test]
    public function it_carries_a_retry_and_a_backoff_policy(): void
    {
        $retry = new AttemptsRetryPolicy(5);
        $backoff = new FixedBackoffPolicy(Duration::seconds(10));

        $policy = new MessageExecutionPolicy(retry: $retry, backoff: $backoff);

        self::assertSame($retry, $policy->retry);
        self::assertSame($backoff, $policy->backoff);
    }
}
