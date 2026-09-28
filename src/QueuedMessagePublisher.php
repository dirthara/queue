<?php

declare(strict_types=1);

namespace Dirthara\Queue;

use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\MessageSerializer;
use Dirthara\Messaging\Contract\MessagePublisher;

final readonly class QueuedMessagePublisher implements MessagePublisher
{
    public function __construct(
        private Queue $queue,
        private MessageSerializer $serializer,
    ) {}

    public function publish(object $message): void
    {
        $this->queue->enqueue($this->serializer->serialize($message));
    }
}
