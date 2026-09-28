<?php

declare(strict_types=1);

namespace Dirthara\Queue\Driver\Memory;

use Closure;
use Throwable;
use DateTimeImmutable;
use Dirthara\Queue\QueuedMessage;
use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\Exception\DeliveryAlreadySettledException;

use function intdiv;
use function sprintf;
use function array_splice;

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

    /**
     * @var Closure(): DateTimeImmutable
     */
    private readonly Closure $now;

    /**
     * @param null|Closure(): DateTimeImmutable $now
     */
    public function __construct(?Closure $now = null)
    {
        $this->now = $now ?? static fn(): DateTimeImmutable => new DateTimeImmutable();
    }

    public function enqueue(QueuedMessage $message): void
    {
        $this->pending[] = new QueueEntry($message);
    }

    public function reserve(): ?Delivery
    {
        $entry = $this->takeAvailable(($this->now)());

        if ($entry === null) {
            return null;
        }

        $release = function (QueueEntry $entry, ?Duration $delay): void {
            $this->pending[] = $entry->retry(self::availableAfter(($this->now)(), $delay));
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
             * @param callable(QueueEntry, ?Duration): void $release
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
            public function release(?Duration $duration = null): void
            {
                $this->guardUnsettled();

                $this->released = true;

                ($this->release)($this->entry, $duration);
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

    private function takeAvailable(DateTimeImmutable $now): ?QueueEntry
    {
        foreach ($this->pending as $index => $entry) {
            if (!$entry->isAvailableAt($now)) {
                continue;
            }

            array_splice($this->pending, $index, length: 1);

            return $entry;
        }

        return null;
    }

    private static function availableAfter(DateTimeImmutable $now, ?Duration $delay): ?DateTimeImmutable
    {
        if ($delay === null || $delay->milliseconds === 0) {
            return null;
        }

        return $now->modify(sprintf(
            '+%d seconds +%d microseconds',
            intdiv($delay->milliseconds, num2: 1000),
            ($delay->milliseconds % 1000) * 1000,
        ));
    }
}
