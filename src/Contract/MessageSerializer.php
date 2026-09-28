<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

use Dirthara\Queue\ValueObject\QueuedMessage;

interface MessageSerializer
{
    public function serialize(object $message): QueuedMessage;

    public function deserialize(QueuedMessage $message): object;
}
