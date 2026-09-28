<?php

declare(strict_types=1);

namespace Dirthara\Queue\Exception;

use Throwable;
use RuntimeException;

use function sprintf;

final class DeliveryAlreadySettledException extends RuntimeException implements QueueException
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

    public static function alreadyAcknowledged(string $message): self
    {
        return new self(
            message: sprintf(
                'Unable to settle the delivery of "%s": it was already acknowledged, and a delivery is settled only once.',
                self::printable($message),
            ),
            context: ['message' => self::printable($message), 'settled' => 'acknowledged'],
        );
    }

    public static function alreadyReleased(string $message): self
    {
        return new self(
            message: sprintf(
                'Unable to settle the delivery of "%s": it was already released, and a delivery is settled only once.',
                self::printable($message),
            ),
            context: ['message' => self::printable($message), 'settled' => 'released'],
        );
    }

    public static function alreadyFailed(string $message): self
    {
        return new self(
            message: sprintf(
                'Unable to settle the delivery of "%s": it was already failed, and a delivery is settled only once.',
                self::printable($message),
            ),
            context: ['message' => self::printable($message), 'settled' => 'failed'],
        );
    }
}
