<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

interface QueueDriverRegistry extends QueueDriverProvider
{
    public function register(string $name, QueueDriver $driver): void;
}
