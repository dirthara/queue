<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Fixtures;

use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\ValueObject\QueuedMessage;

final class CountingQueue implements Queue
{
    public private(set) int $reservations = 0;

    public function __construct(
        private readonly Queue $queue,
    ) {}

    public function enqueue(QueuedMessage $message, ?Duration $delay = null): void
    {
        $this->queue->enqueue($message, $delay);
    }

    public function reserve(): ?Delivery
    {
        $this->reservations++;

        return $this->queue->reserve();
    }
}
