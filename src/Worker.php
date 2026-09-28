<?php

declare(strict_types=1);

namespace Dirthara\Queue;

use Throwable;
use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\ValueObject\WorkerResult;
use Dirthara\Queue\Contract\MessageSerializer;
use Dirthara\Queue\Contract\MessageHandlerProvider;
use Dirthara\Queue\ValueObject\MessageExecutionPolicy;
use Dirthara\Queue\Contract\MessageExecutionPolicyProvider;

final readonly class Worker
{
    public function __construct(
        private Queue $queue,
        private MessageSerializer $serializer,
        private MessageHandlerProvider $handlers,
        private MessageExecutionPolicyProvider $executionPolicies,
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

        $policy = $this->executionPolicies->default;

        try {
            $message = $this->serializer->deserialize($delivery->message);
            $policy = $this->executionPolicies->policyFor($message);
            $handler = $this->handlers->handlerFor($message);

            $handler($message);
        } catch (Throwable $failure) {
            $this->settleFailure($delivery, $failure, $policy);

            return WorkerResult::failed($delivery->message, $delivery->attempt, $failure);
        }

        $delivery->acknowledge();

        return WorkerResult::handled($delivery->message, $delivery->attempt);
    }

    private function settleFailure(Delivery $delivery, Throwable $failure, MessageExecutionPolicy $policy): void
    {
        if (!$policy->retry->shouldRetry($delivery, $failure)) {
            $delivery->fail($failure);

            return;
        }

        $delivery->release($policy->backoff->delay($delivery, $failure));
    }
}
