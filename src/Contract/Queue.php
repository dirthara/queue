<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

use Dirthara\Queue\QueuedMessage;

interface Queue
{
    public function enqueue(QueuedMessage $message): void;

    public function reserve(): ?Delivery;
}
