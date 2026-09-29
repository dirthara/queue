<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

use Dirthara\Queue\ValueObject\QueuedMessage;

interface MessageSerialiser
{
    public function serialise(object $message): QueuedMessage;

    public function deserialise(QueuedMessage $message): object;
}
