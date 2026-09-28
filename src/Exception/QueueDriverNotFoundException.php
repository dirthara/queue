<?php

declare(strict_types=1);

namespace Dirthara\Queue\Exception;

use Throwable;
use RuntimeException;

use function sprintf;

final class QueueDriverNotFoundException extends RuntimeException implements QueueException
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

    public static function for(string $driver): self
    {
        return new self(
            message: sprintf(
                'Unable to find the queue driver "%s": no driver is registered under that name.',
                self::printable($driver),
            ),
            context: [
                'driver' => self::printable($driver),
            ],
        );
    }
}
