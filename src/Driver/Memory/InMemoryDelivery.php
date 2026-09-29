<?php

declare(strict_types=1);

namespace Dirthara\Queue\Driver\Memory;

use Closure;
use Throwable;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Queue\Exception\DeliveryAlreadySettledException;

/**
 * @internal
 */
final class InMemoryDelivery implements Delivery
{
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
     * @param Closure(QueueEntry, ?Duration): void $release
     * @param Closure(QueueEntry, ?Throwable): void $fail
     */
    public function __construct(
        private readonly QueueEntry $entry,
        private readonly Closure $release,
        private readonly Closure $fail,
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
    public function release(?Duration $duration = null): void
    {
        $this->guardUnsettled();

        ($this->release)($this->entry, $duration);

        $this->released = true;
    }

    /**
     * @throws DeliveryAlreadySettledException
     */
    public function fail(?Throwable $throwable = null): void
    {
        $this->guardUnsettled();

        ($this->fail)($this->entry, $throwable);

        $this->failed = true;
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
}
