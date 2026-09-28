<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Messaging\Contract\MessagePublisher;

interface DelayedMessagePublisher extends MessagePublisher
{
    public function publishAfter(object $message, Duration $delay): void;
}
