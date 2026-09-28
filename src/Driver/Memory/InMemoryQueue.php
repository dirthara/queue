<?php

declare(strict_types=1);

namespace Dirthara\Queue\Driver\Memory;

use Closure;
use Throwable;
use DateTimeImmutable;
use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\ValueObject\Failure;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Queue\Contract\FailedMessageRepository;
use Dirthara\Queue\Exception\FailedMessageNotFoundException;
use Dirthara\Queue\Exception\DeliveryAlreadySettledException;

use function intdiv;
use function bin2hex;
use function sprintf;
use function array_splice;
use function array_values;
use function random_bytes;
use function array_key_exists;

final class InMemoryQueue implements Queue, FailedMessageRepository
{
    /**
     * @var list<QueueEntry>
     */
    private array $pending = [];

    /**
     * @var array<string, FailedMessage>
     */
    private array $failed = [];

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

    public function enqueue(QueuedMessage $message, ?Duration $delay = null): void
    {
        $this->pending[] = new QueueEntry($message, availableAt: self::availableAfter(($this->now)(), $delay));
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
            $id = bin2hex(random_bytes(16));

            $this->failed[$id] = new FailedMessage(
                $id,
                $entry->message,
                $entry->attempt,
                $failure === null ? null : Failure::fromThrowable($failure),
            );
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

    /**
     * @return list<FailedMessage>
     */
    public function failed(): iterable
    {
        return array_values($this->failed);
    }

    public function findFailed(string $id): ?FailedMessage
    {
        return $this->failed[$id] ?? null;
    }

    /**
     * @throws FailedMessageNotFoundException
     */
    public function retry(string $id): void
    {
        $failed = $this->failed[$id] ?? throw FailedMessageNotFoundException::forId($id);

        unset($this->failed[$id]);

        $this->pending[] = new QueueEntry($failed->message);
    }

    /**
     * @throws FailedMessageNotFoundException
     */
    public function forget(string $id): void
    {
        if (!array_key_exists($id, $this->failed)) {
            throw FailedMessageNotFoundException::forId($id);
        }

        unset($this->failed[$id]);
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
