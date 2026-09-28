<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

use Dirthara\Queue\ValueObject\FailedMessage;

interface FailedMessageProvider
{
    /**
     * @return iterable<FailedMessage>
     */
    public function failed(): iterable;

    public function findFailed(string $id): ?FailedMessage;
}
