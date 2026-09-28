<?php

declare(strict_types=1);

namespace Dirthara\Queue;

use Closure;
use Throwable;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\Exception\WorkerAlreadyRunningException;

use function sleep;
use function intdiv;
use function usleep;

final class WorkerRunner
{
    private bool $running = false;

    /**
     * @var Closure(Duration): void
     */
    private readonly Closure $sleep;

    /**
     * @param null|Closure(Duration): void $sleep
     */
    public function __construct(
        private readonly Worker $worker,
        private readonly Duration $idleDelay,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (Duration $duration): void {
            // @mago-expect analysis:possibly-invalid-argument A duration is never negative, so neither are its whole seconds
            sleep(intdiv($duration->milliseconds, num2: 1000));
            usleep(($duration->milliseconds % 1000) * 1000);
        };
    }

    /**
     * @throws WorkerAlreadyRunningException
     * @throws Throwable
     */
    public function run(): void
    {
        if ($this->running) {
            throw WorkerAlreadyRunningException::alreadyRunning();
        }

        $this->running = true;

        try {
            $this->loop();
        } finally {
            $this->running = false;
        }
    }

    /**
     * @throws Throwable
     */
    private function loop(): void
    {
        while ($this->running) {
            if ($this->worker->runOnce()->outcome !== WorkerOutcome::Idle) {
                continue;
            }

            ($this->sleep)($this->idleDelay);
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }
}
