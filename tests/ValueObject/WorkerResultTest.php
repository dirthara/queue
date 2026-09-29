<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\ValueObject;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use Dirthara\Queue\WorkerOutcome;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\ValueObject\WorkerResult;
use Dirthara\Queue\ValueObject\QueuedMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Queue\Exception\InvalidWorkerResultException;

final class WorkerResultTest extends TestCase
{
    #[Test]
    public function it_describes_an_idle_run_without_a_message_attempt_or_failure(): void
    {
        $result = WorkerResult::idle();

        self::assertSame(WorkerOutcome::Idle, $result->outcome);
        self::assertNull($result->message);
        self::assertNull($result->attempt);
        self::assertNull($result->failure);
    }

    #[Test]
    public function it_describes_a_handled_message_and_its_attempt_without_a_failure(): void
    {
        $message = new QueuedMessage('type', 'payload');

        $result = WorkerResult::handled($message, 2);

        self::assertSame(WorkerOutcome::Handled, $result->outcome);
        self::assertSame($message, $result->message);
        self::assertSame(2, $result->attempt);
        self::assertNull($result->failure);
    }

    #[Test]
    public function it_describes_a_released_message_its_attempt_and_its_failure(): void
    {
        $message = new QueuedMessage('type', 'payload');
        $failure = new RuntimeException('The handler failed.');

        $result = WorkerResult::released($message, 1, $failure);

        self::assertSame(WorkerOutcome::Released, $result->outcome);
        self::assertSame($message, $result->message);
        self::assertSame(1, $result->attempt);
        self::assertSame($failure, $result->failure);
    }

    #[Test]
    public function it_describes_a_failed_message_its_attempt_and_its_failure(): void
    {
        $message = new QueuedMessage('type', 'payload');
        $failure = new RuntimeException('The handler failed.');

        $result = WorkerResult::failed($message, 3, $failure);

        self::assertSame(WorkerOutcome::Failed, $result->outcome);
        self::assertSame($message, $result->message);
        self::assertSame(3, $result->attempt);
        self::assertSame($failure, $result->failure);
    }

    #[Test]
    public function it_accepts_a_first_attempt(): void
    {
        $message = new QueuedMessage('type', 'payload');

        self::assertSame(1, WorkerResult::handled($message, 1)->attempt);
        self::assertSame(1, WorkerResult::released($message, 1, new RuntimeException('failure'))->attempt);
        self::assertSame(1, WorkerResult::failed($message, 1, new RuntimeException('failure'))->attempt);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function attemptsBeforeTheFirst(): iterable
    {
        yield 'zero' => [0];
        yield 'a negative attempt' => [-1];
    }

    #[Test]
    #[DataProvider('attemptsBeforeTheFirst')]
    public function it_refuses_a_handled_result_for_an_attempt_before_the_first(int $attempt): void
    {
        try {
            WorkerResult::handled(new QueuedMessage('type', 'payload'), $attempt);
            self::fail('An attempt before the first was accepted.');
        } catch (InvalidWorkerResultException $exception) {
            self::assertSame(['attempt' => $attempt], $exception->context);
        }
    }

    #[Test]
    #[DataProvider('attemptsBeforeTheFirst')]
    public function it_refuses_a_released_result_for_an_attempt_before_the_first(int $attempt): void
    {
        try {
            WorkerResult::released(new QueuedMessage('type', 'payload'), $attempt, new RuntimeException('failure'));
            self::fail('An attempt before the first was accepted.');
        } catch (InvalidWorkerResultException $exception) {
            self::assertSame(['attempt' => $attempt], $exception->context);
        }
    }

    #[Test]
    #[DataProvider('attemptsBeforeTheFirst')]
    public function it_refuses_a_failed_result_for_an_attempt_before_the_first(int $attempt): void
    {
        try {
            WorkerResult::failed(new QueuedMessage('type', 'payload'), $attempt, new RuntimeException('failure'));
            self::fail('An attempt before the first was accepted.');
        } catch (InvalidWorkerResultException $exception) {
            self::assertSame(['attempt' => $attempt], $exception->context);
        }
    }
}
