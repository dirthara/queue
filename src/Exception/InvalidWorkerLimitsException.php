<?php

declare(strict_types=1);

namespace Dirthara\Queue\Exception;

use Throwable;
use InvalidArgumentException;

use function sprintf;

final class InvalidWorkerLimitsException extends InvalidArgumentException implements QueueException
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

    public static function tooFewMessages(int $maxMessages): self
    {
        return new self(
            message: sprintf(
                'Unable to limit a worker to %d messages: a limited worker processes at least one message.',
                $maxMessages,
            ),
            context: ['maxMessages' => $maxMessages],
        );
    }

    public static function noRuntime(): self
    {
        return new self(message: 'Unable to limit a worker to a runtime of 0 milliseconds: a limited worker runs for at least 1 millisecond.', context: [
            'maxRuntime' => 0,
        ]);
    }
}
