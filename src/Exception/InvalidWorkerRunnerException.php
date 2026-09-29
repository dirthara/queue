<?php

declare(strict_types=1);

namespace Dirthara\Queue\Exception;

use Throwable;
use InvalidArgumentException;

final class InvalidWorkerRunnerException extends InvalidArgumentException implements QueueException
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

    public static function noIdleDelay(): self
    {
        return new self(message: 'Unable to run a worker with an idle delay of 0 milliseconds: '
        . 'an idle worker would poll its queue without pausing, so the delay has to be at least 1 millisecond.', context: [
            'idleDelay' => 0,
        ]);
    }
}
