<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Observer;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Contract\WorkerObserver;
use Dirthara\Queue\ValueObject\WorkerResult;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Queue\Observer\NullWorkerObserver;

final class NullWorkerObserverTest extends TestCase
{
    #[Test]
    public function it_is_a_worker_observer(): void
    {
        self::assertInstanceOf(WorkerObserver::class, new NullWorkerObserver());
    }

    #[Test]
    public function it_accepts_every_result_without_doing_anything(): void
    {
        $observer = new NullWorkerObserver();
        $message = new QueuedMessage('type', 'payload');

        $observer->observe(WorkerResult::idle());
        $observer->observe(WorkerResult::handled($message, 1));
        $observer->observe(WorkerResult::failed($message, 2, new RuntimeException('failure')));

        $this->expectNotToPerformAssertions();
    }
}
