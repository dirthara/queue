<?php

declare(strict_types=1);

namespace Dirthara\Queue\Exception;

use Throwable;
use InvalidArgumentException;

use function sprintf;

final class DuplicateMessageHandlerException extends InvalidArgumentException implements QueueException
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

    public static function alreadyRegistered(string $message): self
    {
        return new self(
            message: sprintf(
                'Unable to register a handler for "%s": the message type already has a handler, and a handler is never replaced.',
                self::printable($message),
            ),
            context: ['message' => self::printable($message)],
        );
    }
}
