<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Fixtures;

use Closure;
use Dirthara\Queue\Contract\WorkerObserver;
use Dirthara\Queue\ValueObject\WorkerResult;

final class RecordingWorkerObserver implements WorkerObserver
{
    /**
     * @var list<WorkerResult>
     */
    public private(set) array $observed = [];

    /**
     * @param null|Closure(WorkerResult): void $then
     */
    public function __construct(
        private readonly ?Closure $then = null,
    ) {}

    public function observe(WorkerResult $result): void
    {
        $this->observed[] = $result;

        if ($this->then !== null) {
            ($this->then)($result);
        }
    }
}
