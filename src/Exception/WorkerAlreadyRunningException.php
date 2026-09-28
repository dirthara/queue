<?php

declare(strict_types=1);

namespace Dirthara\Queue\Exception;

use Throwable;
use RuntimeException;

final class WorkerAlreadyRunningException extends RuntimeException implements QueueException
{
    use HasExceptionContext;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);

        $this->context = $context;
    }

    public static function alreadyRunning(): self
    {
        return new self(
            message: 'Unable to run the worker: it is already running, and a runner runs one loop at a time.',
        );
    }
}
