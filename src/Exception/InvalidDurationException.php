<?php

declare(strict_types=1);

namespace Dirthara\Queue\Exception;

use Throwable;
use InvalidArgumentException;

use function sprintf;

final class InvalidDurationException extends InvalidArgumentException implements QueueException
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

    public static function negative(int $amount, string $unit): self
    {
        return new self(
            message: sprintf('Unable to create a duration of %d %s: a duration cannot be negative.', $amount, $unit),
            context: ['amount' => $amount, 'unit' => $unit],
        );
    }

    public static function tooLong(int $amount, string $unit): self
    {
        return new self(
            message: sprintf(
                'Unable to create a duration of %d %s: it does not fit in a whole number of milliseconds.',
                $amount,
                $unit,
            ),
            context: ['amount' => $amount, 'unit' => $unit],
        );
    }
}
