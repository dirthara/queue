<?php

declare(strict_types=1);

namespace Dirthara\Queue\Exception;

use Throwable;
use RuntimeException;

use function sprintf;

/**
 * The payload is never part of the message or the context, because a message can carry personal data.
 */
final class MessageSerializationException extends RuntimeException implements QueueException
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

    public static function unableToSerialize(string $message, ?Throwable $previous = null): self
    {
        return new self(
            message: sprintf('Unable to serialize a message of type "%s".', self::printable($message)),
            previous: $previous,
            context: ['message' => self::printable($message)],
        );
    }

    public static function unableToDeserialize(string $message, ?Throwable $previous = null): self
    {
        return new self(
            message: sprintf(
                'Unable to deserialize a message of type "%s": the payload is malformed.',
                self::printable($message),
            ),
            previous: $previous,
            context: ['message' => self::printable($message)],
        );
    }

    public static function notAnObject(string $message, string $actual): self
    {
        return new self(
            message: sprintf(
                'Unable to deserialize a message of type "%s": the payload holds %s, not an object.',
                self::printable($message),
                self::printable($actual),
            ),
            context: ['message' => self::printable($message), 'actual' => self::printable($actual)],
        );
    }

    public static function typeMismatch(string $message, string $actual): self
    {
        return new self(
            message: sprintf(
                'Unable to deserialize a message of type "%s": the payload holds a "%s".',
                self::printable($message),
                self::printable($actual),
            ),
            context: ['message' => self::printable($message), 'actual' => self::printable($actual)],
        );
    }
}
