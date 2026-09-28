<?php

declare(strict_types=1);

namespace Dirthara\Queue\Exception;

use Throwable;
use InvalidArgumentException;

use function sprintf;

final class InvalidMessageTypeException extends InvalidArgumentException implements QueueException
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

    public static function notAnObjectType(string $message): self
    {
        return new self(
            message: sprintf(
                'Unable to register a handler for "%s": a message type has to be an existing class or enum.',
                self::printable($message),
            ),
            context: ['message' => self::printable($message)],
        );
    }

    public static function interfaceType(string $message): self
    {
        return new self(
            message: sprintf(
                'Unable to register a handler for "%s": it is an interface, and messages are handled by their exact class.',
                self::printable($message),
            ),
            context: ['message' => self::printable($message)],
        );
    }

    public static function abstractClass(string $message): self
    {
        return new self(
            message: sprintf(
                'Unable to register a handler for "%s": it is an abstract class, and messages are handled by their exact class.',
                self::printable($message),
            ),
            context: ['message' => self::printable($message)],
        );
    }
}
