<?php

declare(strict_types=1);

namespace Dirthara\Queue;

use Closure;
use Throwable;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\Contract\WorkerObserver;
use Dirthara\Queue\ValueObject\WorkerLimits;
use Dirthara\Queue\Observer\NullWorkerObserver;
use Dirthara\Queue\Exception\InvalidWorkerRunnerException;
use Dirthara\Queue\Exception\WorkerAlreadyRunningException;

use function min;
use function sleep;
use function hrtime;
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
     * @var Closure(): int
     */
    private readonly Closure $nanoseconds;

    /**
     * @param null|Closure(Duration): void $sleep
     * @param null|Closure(): int $nanoseconds
     *
     * @throws InvalidWorkerRunnerException
     */
    public function __construct(
        private readonly Worker $worker,
        private readonly Duration $idleDelay,
        private readonly WorkerObserver $observer = new NullWorkerObserver(),
        private readonly WorkerLimits $limits = new WorkerLimits(),
        ?Closure $sleep = null,
        ?Closure $nanoseconds = null,
    ) {
        if ($idleDelay->milliseconds === 0) {
            throw InvalidWorkerRunnerException::noIdleDelay();
        }

        $this->sleep = $sleep ?? static function (Duration $duration): void {
            // @mago-expect analysis:possibly-invalid-argument A duration is never negative, so neither are its whole seconds
            sleep(intdiv($duration->milliseconds, num2: 1000));
            usleep(($duration->milliseconds % 1000) * 1000);
        };
        $this->nanoseconds = $nanoseconds ?? static fn(): int => (int) hrtime(true);
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
            $this->loop(($this->nanoseconds)());
        } finally {
            $this->running = false;
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * @throws Throwable
     */
    private function loop(int $startedAt): void
    {
        $processed = 0;

        while ($this->running && !$this->limitReached($startedAt, $processed)) {
            $result = $this->worker->runOnce();

            $this->observer->observe($result);

            if ($result->outcome !== WorkerOutcome::Idle) {
                $processed++;

                continue;
            }

            if ($this->stopRequested() || $this->limitReached($startedAt, $processed)) {
                continue;
            }

            ($this->sleep)($this->idleSleep($startedAt));
        }
    }

    private function stopRequested(): bool
    {
        return !$this->running;
    }

    private function limitReached(int $startedAt, int $processed): bool
    {
        if ($this->limits->maxMessages !== null && $processed >= $this->limits->maxMessages) {
            return true;
        }

        return $this->remainingMilliseconds($startedAt) === 0;
    }

    private function idleSleep(int $startedAt): Duration
    {
        $remaining = $this->remainingMilliseconds($startedAt);

        if ($remaining === null) {
            return $this->idleDelay;
        }

        return Duration::milliseconds(min($this->idleDelay->milliseconds, $remaining));
    }

    private function remainingMilliseconds(int $startedAt): ?int
    {
        if ($this->limits->maxRuntime === null) {
            return null;
        }

        $elapsed = intdiv(($this->nanoseconds)() - $startedAt, num2: 1_000_000);

        return $elapsed >= $this->limits->maxRuntime->milliseconds
            ? 0
            : $this->limits->maxRuntime->milliseconds - $elapsed;
    }
}
