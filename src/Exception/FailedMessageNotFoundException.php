<?php

declare(strict_types=1);

namespace Dirthara\Queue\Exception;

use Throwable;
use RuntimeException;

use function sprintf;

final class FailedMessageNotFoundException extends RuntimeException implements QueueException
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

    public static function forId(string $id): self
    {
        return new self(
            message: sprintf(
                'Unable to find the failed message "%s": no failed message has that id.',
                self::printable($id),
            ),
            context: ['id' => self::printable($id)],
        );
    }
}
