<?php

declare(strict_types=1);

namespace Dirthara\Queue\Driver\Memory;

use Dirthara\Queue\Contract\QueueDriver;
use Dirthara\Queue\Config\QueueConfiguration;
use Dirthara\Queue\Contract\Queue as QueueContract;

final readonly class MemoryQueueDriver implements QueueDriver
{
    public function create(QueueConfiguration $configuration): QueueContract
    {
        return new InMemoryQueue();
    }
}
