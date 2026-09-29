<?php

declare(strict_types=1);

namespace Dirthara\Queue;

use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\Contract\MessageSerialiser;
use Dirthara\Messaging\Contract\MessagePublisher;
use Dirthara\Queue\Contract\DelayedMessagePublisher;

final readonly class QueuedMessagePublisher implements MessagePublisher, DelayedMessagePublisher
{
    public function __construct(
        private Queue $queue,
        private MessageSerialiser $serialiser,
    ) {}

    public function publish(object $message): void
    {
        $this->queue->enqueue($this->serialiser->serialise($message));
    }

    public function publishAfter(object $message, Duration $delay): void
    {
        $this->queue->enqueue($this->serialiser->serialise($message), $delay);
    }
}
