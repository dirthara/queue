<?php

declare(strict_types=1);

namespace Dirthara\Queue;

use Throwable;
use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\Contract\RetryPolicy;
use Dirthara\Queue\Contract\BackoffPolicy;
use Dirthara\Queue\Contract\MessageSerializer;
use Dirthara\Queue\Contract\MessageHandlerProvider;

final readonly class Worker
{
    public function __construct(
        private Queue $queue,
        private MessageSerializer $serializer,
        private MessageHandlerProvider $handlers,
        private RetryPolicy $retryPolicy,
        private BackoffPolicy $backoffPolicy,
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

        try {
            $message = $this->serializer->deserialize($delivery->message);
            $handler = $this->handlers->handlerFor($message);

            $handler($message);
        } catch (Throwable $exception) {
            $this->settleFailure($delivery, $exception);

            throw $exception;
        }

        $delivery->acknowledge();

        return true;
    }

    private function settleFailure(Delivery $delivery, Throwable $failure): void
    {
        if (!$this->retryPolicy->shouldRetry($delivery, $failure)) {
            $delivery->fail($failure);

            return;
        }

        $delivery->release($this->backoffPolicy->delay($delivery, $failure));
    }
}
