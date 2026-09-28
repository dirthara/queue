<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Fixtures;

use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\QueueDriver;
use Dirthara\Queue\Config\QueueConfiguration;
use Dirthara\Queue\Driver\Memory\InMemoryQueue;

final class RecordingQueueDriver implements QueueDriver
{
    /**
     * @var list<QueueConfiguration>
     */
    public private(set) array $configurations = [];

    public function create(QueueConfiguration $configuration): Queue
    {
        $this->configurations[] = $configuration;

        return new InMemoryQueue();
    }
}
