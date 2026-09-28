<?php

declare(strict_types=1);

namespace Dirthara\Queue;

use Throwable;
use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\Contract\RetryPolicy;
use Dirthara\Queue\Contract\BackoffPolicy;
use Dirthara\Queue\ValueObject\WorkerResult;
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
    public function runOnce(): WorkerResult
    {
        $delivery = $this->queue->reserve();

        if ($delivery === null) {
            return WorkerResult::idle();
        }

        try {
            $message = $this->serializer->deserialize($delivery->message);
            $handler = $this->handlers->handlerFor($message);

            $handler($message);
        } catch (Throwable $failure) {
            $this->settleFailure($delivery, $failure);

            return WorkerResult::failed($failure);
        }

        $delivery->acknowledge();

        return WorkerResult::handled();
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
