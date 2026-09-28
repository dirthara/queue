<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

interface QueueDriverProvider
{
    public function has(string $name): bool;

    public function driver(string $name): QueueDriver;
}
