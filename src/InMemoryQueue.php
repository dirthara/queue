<?php

declare(strict_types=1);

namespace Dirthara\Queue;

use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\Exception\DeliveryAlreadySettledException;

use function array_shift;

final class InMemoryQueue implements Queue
{
    /**
     * @var list<QueuedMessage>
     */
    private array $pending = [];

    public function enqueue(QueuedMessage $message): void
    {
        $this->pending[] = $message;
    }

    public function reserve(): ?Delivery
    {
        $message = array_shift($this->pending);

        if ($message === null) {
            return null;
        }

        return new class($message, $this->enqueue(...)) implements Delivery {
            private bool $acknowledged = false;

            private bool $released = false;

            /**
             * @param callable(QueuedMessage): void $release
             */
            public function __construct(
                public readonly QueuedMessage $message,
                private readonly mixed $release,
            ) {}

            /**
             * @throws DeliveryAlreadySettledException
             */
            public function acknowledge(): void
            {
                $this->guardUnsettled();

                $this->acknowledged = true;
            }

            /**
             * @throws DeliveryAlreadySettledException
             */
            public function release(): void
            {
                $this->guardUnsettled();

                $this->released = true;

                ($this->release)($this->message);
            }

            /**
             * @throws DeliveryAlreadySettledException
             */
            private function guardUnsettled(): void
            {
                if ($this->acknowledged) {
                    throw DeliveryAlreadySettledException::alreadyAcknowledged($this->message->type);
                }

                if ($this->released) {
                    throw DeliveryAlreadySettledException::alreadyReleased($this->message->type);
                }
            }
        };
    }
}
