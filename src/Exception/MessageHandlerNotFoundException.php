<?php

declare(strict_types=1);

namespace Dirthara\Queue\Exception;

use Throwable;
use RuntimeException;

use function sprintf;

final class MessageHandlerNotFoundException extends RuntimeException implements QueueException
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

    public static function noHandlerFor(string $message): self
    {
        return new self(
            message: sprintf(
                'Unable to handle "%s": no handler is registered for the message type.',
                self::printable($message),
            ),
            context: ['message' => self::printable($message)],
        );
    }
}
