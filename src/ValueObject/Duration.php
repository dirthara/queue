<?php

declare(strict_types=1);

namespace Dirthara\Queue\ValueObject;

use Dirthara\Queue\Exception\InvalidDurationException;

use function intdiv;

use const PHP_INT_MAX;

final readonly class Duration
{
    private function __construct(
        public int $milliseconds,
    ) {}

    /**
     * @throws InvalidDurationException
     */
    public static function milliseconds(int $milliseconds): self
    {
        return new self(self::scale($milliseconds, 1, 'milliseconds'));
    }

    /**
     * @throws InvalidDurationException
     */
    public static function seconds(int $seconds): self
    {
        return new self(self::scale($seconds, 1000, 'seconds'));
    }

    /**
     * @throws InvalidDurationException
     */
    public static function minutes(int $minutes): self
    {
        return new self(self::scale($minutes, 60_000, 'minutes'));
    }

    /**
     * @throws InvalidDurationException
     */
    public static function hours(int $hours): self
    {
        return new self(self::scale($hours, 3_600_000, 'hours'));
    }

    /**
     * @throws InvalidDurationException
     */
    private static function scale(int $amount, int $millisecondsPerUnit, string $unit): int
    {
        if ($amount < 0) {
            throw InvalidDurationException::negative($amount, $unit);
        }

        if ($amount > intdiv(PHP_INT_MAX, $millisecondsPerUnit)) {
            throw InvalidDurationException::tooLong($amount, $unit);
        }

        return $amount * $millisecondsPerUnit;
    }
}
