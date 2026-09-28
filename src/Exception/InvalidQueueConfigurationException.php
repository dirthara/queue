<?php

declare(strict_types=1);

namespace Dirthara\Queue\Exception;

use Throwable;
use InvalidArgumentException;

use function sprintf;
use function get_debug_type;

final class InvalidQueueConfigurationException extends InvalidArgumentException implements QueueException
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

    public static function missingOption(string $driver, string $key): self
    {
        return new self(
            message: sprintf(
                'Unable to configure the "%s" queue: the option "%s" is required and has no default.',
                self::printable($driver),
                self::printable($key),
            ),
            context: ['driver' => self::printable($driver), 'option' => self::printable($key)],
        );
    }

    /**
     * The value itself is left out of the message and the context, because an option can hold a credential.
     */
    public static function invalidOptionType(string $driver, string $key, string $expected, mixed $value): self
    {
        return new self(
            message: sprintf(
                'Unable to configure the "%s" queue: the option "%s" has to be of type %s, %s given.',
                self::printable($driver),
                self::printable($key),
                $expected,
                get_debug_type($value),
            ),
            context: [
                'driver' => self::printable($driver),
                'option' => self::printable($key),
                'expected' => $expected,
                'actual' => get_debug_type($value),
            ],
        );
    }
}
