<?php

declare(strict_types=1);

namespace Dirthara\Queue\Driver\Memory;

use Dirthara\Queue\ValueObject\Failure;
use Dirthara\Queue\ValueObject\QueuedMessage;

final readonly class FailedMessage
{
    public function __construct(
        public string $id,
        public QueuedMessage $message,
        public int $attempt,
        public ?Failure $failure = null,
    ) {}
}
