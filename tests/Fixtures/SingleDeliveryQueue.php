<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Fixtures;

use Throwable;
use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\ValueObject\QueuedMessage;

final class SingleDeliveryQueue implements Queue
{
    private bool $reserved = false;

    public function __construct(
        private readonly QueuedMessage $message,
        private readonly ?Throwable $settlementFailure,
    ) {}

    public function enqueue(QueuedMessage $message, ?Duration $delay = null): void {}

    public function reserve(): ?Delivery
    {
        if ($this->reserved) {
            return null;
        }

        $this->reserved = true;

        return new readonly class($this->message, $this->settlementFailure) implements Delivery {
            public int $attempt;

            public function __construct(
                public QueuedMessage $message,
                private ?Throwable $settlementFailure,
            ) {
                $this->attempt = 1;
            }

            public function acknowledge(): void
            {
                $this->settle();
            }

            public function release(?Duration $duration = null): void
            {
                $this->settle();
            }

            public function fail(?Throwable $throwable = null): void
            {
                $this->settle();
            }

            private function settle(): void
            {
                if ($this->settlementFailure !== null) {
                    throw $this->settlementFailure;
                }
            }
        };
    }
}
