<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\ValueObject\QueuedMessage;

interface Queue
{
    public function enqueue(QueuedMessage $message, ?Duration $delay = null): void;

    public function reserve(): ?Delivery;
}
