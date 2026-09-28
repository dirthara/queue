<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Fixtures;

use LogicException;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Queue\Driver\Memory\InMemoryQueue;

final class Deliveries
{
    public static function onAttempt(int $attempt): Delivery
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage('type', 'payload'));

        for ($released = 1; $released < $attempt; $released++) {
            $queue->reserve()?->release();
        }

        $delivery = $queue->reserve() ?? throw new LogicException('The queue lost the message.');

        if ($delivery->attempt !== $attempt) {
            throw new LogicException('The queue did not count the attempts.');
        }

        return $delivery;
    }
}
