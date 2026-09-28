<?php

declare(strict_types=1);

namespace Dirthara\Queue\Driver\Memory;

use DateTimeImmutable;
use Dirthara\Queue\QueuedMessage;

/**
 * @internal
 */
final readonly class QueueEntry
{
    public function __construct(
        public QueuedMessage $message,
        public int $attempt = 1,
        public ?DateTimeImmutable $availableAt = null,
    ) {}

    public function retry(?DateTimeImmutable $availableAt = null): self
    {
        return new self($this->message, $this->attempt + 1, $availableAt);
    }

    public function isAvailableAt(DateTimeImmutable $now): bool
    {
        return $this->availableAt === null || $this->availableAt <= $now;
    }
}
