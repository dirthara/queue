<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

use Dirthara\Queue\ValueObject\WorkerResult;

interface WorkerObserver
{
    public function observe(WorkerResult $result): void;
}
