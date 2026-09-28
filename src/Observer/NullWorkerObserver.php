<?php

declare(strict_types=1);

namespace Dirthara\Queue\Observer;

use Dirthara\Queue\Contract\WorkerObserver;
use Dirthara\Queue\ValueObject\WorkerResult;

final readonly class NullWorkerObserver implements WorkerObserver
{
    public function observe(WorkerResult $result): void {}
}
