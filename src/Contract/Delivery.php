<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

use Dirthara\Queue\QueuedMessage;

interface Delivery
{
    public QueuedMessage $message { get; }

    public function acknowledge(): void;

    public function release(): void;
}
