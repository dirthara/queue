<?php

declare(strict_types=1);

namespace Dirthara\Queue\Exception;

use Throwable;
use InvalidArgumentException;

use function sprintf;

final class DuplicateQueueDriverException extends InvalidArgumentException implements QueueException
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

    public static function alreadyRegistered(string $driver): self
    {
        return new self(
            message: sprintf(
                'Unable to register the queue driver "%s": a driver is already registered under that name, and a driver is never replaced.',
                self::printable($driver),
            ),
            context: ['driver' => self::printable($driver)],
        );
    }
}
