<?php

declare(strict_types=1);

namespace Dirthara\Queue\ValueObject;

final readonly class FailedMessage
{
    public function __construct(
        public string $id,
        public QueuedMessage $message,
        public int $attempt,
        public ?Failure $failure = null,
    ) {}
}
