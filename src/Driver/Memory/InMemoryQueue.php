<?php

declare(strict_types=1);

namespace Dirthara\Queue\Driver\Memory;

use Throwable;
use Dirthara\Queue\QueuedMessage;
use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\Exception\DeliveryAlreadySettledException;

use function array_shift;

final class InMemoryQueue implements Queue
{
    /**
     * @var list<QueueEntry>
     */
    private array $pending = [];

    /**
     * @var list<FailedMessage>
     */
    public private(set) array $failed = [];

    public function enqueue(QueuedMessage $message): void
    {
        $this->pending[] = new QueueEntry($message);
    }

    public function reserve(): ?Delivery
    {
        $entry = array_shift($this->pending);

        if ($entry === null) {
            return null;
        }

        $release = function (QueueEntry $entry): void {
            $this->pending[] = $entry->retry();
        };

        $fail = function (QueueEntry $entry, ?Throwable $failure): void {
            $this->failed[] = new FailedMessage($entry->message, $failure);
        };

        return new class($entry, $release, $fail) implements Delivery {
            public QueuedMessage $message {
                get => $this->entry->message;
            }

            public int $attempt {
                get => $this->entry->attempt;
            }

            private bool $acknowledged = false;

            private bool $released = false;

            private bool $failed = false;

            /**
             * @param callable(QueueEntry): void $release
             * @param callable(QueueEntry, ?Throwable): void $fail
             */
            public function __construct(
                private readonly QueueEntry $entry,
                private readonly mixed $release,
                private readonly mixed $fail,
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

                ($this->release)($this->entry);
            }

            /**
             * @throws DeliveryAlreadySettledException
             */
            public function fail(?Throwable $throwable = null): void
            {
                $this->guardUnsettled();

                $this->failed = true;

                ($this->fail)($this->entry, $throwable);
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

                if ($this->failed) {
                    throw DeliveryAlreadySettledException::alreadyFailed($this->message->type);
                }
            }
        };
    }
}
