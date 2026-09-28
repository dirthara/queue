<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Retry;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Retry\AttemptsRetryPolicy;
use Dirthara\Queue\Tests\Fixtures\Deliveries;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Queue\Exception\InvalidRetryPolicyException;

final class AttemptsRetryPolicyTest extends TestCase
{
    #[Test]
    public function it_allows_three_attempts_by_default(): void
    {
        $policy = new AttemptsRetryPolicy();
        $failure = new RuntimeException('failure');

        self::assertSame(3, $policy->maxAttempts);
        self::assertTrue($policy->shouldRetry(Deliveries::onAttempt(1), $failure));
        self::assertTrue($policy->shouldRetry(Deliveries::onAttempt(2), $failure));
        self::assertFalse($policy->shouldRetry(Deliveries::onAttempt(3), $failure));
    }

    #[Test]
    public function it_retries_until_the_last_allowed_attempt(): void
    {
        $policy = new AttemptsRetryPolicy(5);
        $failure = new RuntimeException('failure');

        self::assertTrue($policy->shouldRetry(Deliveries::onAttempt(4), $failure));
        self::assertFalse($policy->shouldRetry(Deliveries::onAttempt(5), $failure));
        self::assertFalse($policy->shouldRetry(Deliveries::onAttempt(6), $failure));
    }

    #[Test]
    public function it_never_retries_when_a_single_attempt_is_allowed(): void
    {
        self::assertFalse(new AttemptsRetryPolicy(1)->shouldRetry(
            Deliveries::onAttempt(1),
            new RuntimeException('failure'),
        ));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function tooFewAttempts(): iterable
    {
        yield 'zero' => [0];
        yield 'a negative number' => [-1];
    }

    #[Test]
    #[DataProvider('tooFewAttempts')]
    public function it_refuses_fewer_than_one_attempt(int $maxAttempts): void
    {
        try {
            new AttemptsRetryPolicy($maxAttempts);
            self::fail('Fewer than one attempt was accepted.');
        } catch (InvalidRetryPolicyException $exception) {
            self::assertSame(['maxAttempts' => $maxAttempts], $exception->context);
        }
    }
}
