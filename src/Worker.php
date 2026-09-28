<?php

declare(strict_types=1);

namespace Dirthara\Queue;

use Throwable;
use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\MessageSerializer;
use Dirthara\Queue\Contract\MessageHandlerProvider;

final readonly class Worker
{
    public function __construct(
        private Queue $queue,
        private MessageSerializer $serializer,
        private MessageHandlerProvider $handlers,
    ) {}

    /**
     * @throws Throwable
     */
    public function runOnce(): bool
    {
        $delivery = $this->queue->reserve();

        if ($delivery === null) {
            return false;
        }

        $message = $this->serializer->deserialize($delivery->message);

        $handler = $this->handlers->handlerFor($message);

        try {
            $handler($message);
        } catch (Throwable $exception) {
            $delivery->release();

            throw $exception;
        }

        $delivery->acknowledge();

        return true;
    }
}
