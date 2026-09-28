<?php

declare(strict_types=1);

namespace Dirthara\Queue\Exception;

use Throwable;
use InvalidArgumentException;

use function sprintf;

final class InvalidRetryPolicyException extends InvalidArgumentException implements QueueException
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

    public static function tooFewAttempts(int $maxAttempts): self
    {
        return new self(
            message: sprintf(
                'Unable to limit a message to %d attempts: a message is always attempted at least once.',
                $maxAttempts,
            ),
            context: ['maxAttempts' => $maxAttempts],
        );
    }
}
