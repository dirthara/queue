<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Fixtures;

use RuntimeException;
use Dirthara\Queue\Exception\QueueException;
use Dirthara\Queue\Exception\HasExceptionContext;

final class ContextualException extends RuntimeException implements QueueException
{
    use HasExceptionContext;

    public static function describe(string $value): string
    {
        return self::printable($value);
    }
}
