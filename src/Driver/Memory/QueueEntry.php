<?php

declare(strict_types=1);

namespace Dirthara\Queue\Driver\Memory;

use Dirthara\Queue\QueuedMessage;

/**
 * @internal
 */
final readonly class QueueEntry
{
    public function __construct(
        public QueuedMessage $message,
        public int $attempt = 1,
    ) {}

    public function retry(): self
    {
        return new self($this->message, $this->attempt + 1);
    }
}
