<?php

declare(strict_types=1);

namespace Dirthara\Queue\Driver\Memory;

use Throwable;
use Dirthara\Queue\QueuedMessage;

final readonly class FailedMessage
{
    public function __construct(
        public QueuedMessage $message,
        public ?Throwable $failure = null,
    ) {}
}
