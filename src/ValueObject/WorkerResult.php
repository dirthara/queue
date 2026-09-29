<?php

declare(strict_types=1);

namespace Dirthara\Queue\ValueObject;

use Throwable;
use Dirthara\Queue\WorkerOutcome;
use Dirthara\Queue\Exception\InvalidWorkerResultException;

final readonly class WorkerResult
{
    private function __construct(
        public WorkerOutcome $outcome,
        public ?QueuedMessage $message = null,
        public ?int $attempt = null,
        public ?Throwable $failure = null,
    ) {}

    public static function idle(): self
    {
        return new self(WorkerOutcome::Idle);
    }

    /**
     * @throws InvalidWorkerResultException
     */
    public static function handled(QueuedMessage $message, int $attempt): self
    {
        return new self(WorkerOutcome::Handled, $message, self::attempt($attempt));
    }

    /**
     * @throws InvalidWorkerResultException
     */
    public static function released(QueuedMessage $message, int $attempt, Throwable $failure): self
    {
        return new self(WorkerOutcome::Released, $message, self::attempt($attempt), $failure);
    }

    /**
     * @throws InvalidWorkerResultException
     */
    public static function failed(QueuedMessage $message, int $attempt, Throwable $failure): self
    {
        return new self(WorkerOutcome::Failed, $message, self::attempt($attempt), $failure);
    }

    /**
     * @throws InvalidWorkerResultException
     */
    private static function attempt(int $attempt): int
    {
        if ($attempt < 1) {
            throw InvalidWorkerResultException::attemptBeforeFirst($attempt);
        }

        return $attempt;
    }
}
