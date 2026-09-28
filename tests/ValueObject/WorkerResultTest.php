<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\ValueObject;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use Dirthara\Queue\WorkerOutcome;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\ValueObject\WorkerResult;

final class WorkerResultTest extends TestCase
{
    #[Test]
    public function it_describes_an_idle_run_without_a_failure(): void
    {
        $result = WorkerResult::idle();

        self::assertSame(WorkerOutcome::Idle, $result->outcome);
        self::assertNull($result->failure);
    }

    #[Test]
    public function it_describes_a_handled_message_without_a_failure(): void
    {
        $result = WorkerResult::handled();

        self::assertSame(WorkerOutcome::Handled, $result->outcome);
        self::assertNull($result->failure);
    }

    #[Test]
    public function it_describes_a_failed_message_with_its_failure(): void
    {
        $failure = new RuntimeException('The handler failed.');

        $result = WorkerResult::failed($failure);

        self::assertSame(WorkerOutcome::Failed, $result->outcome);
        self::assertSame($failure, $result->failure);
    }
}
