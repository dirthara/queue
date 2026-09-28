<?php

declare(strict_types=1);

namespace Dirthara\Queue\ValueObject;

use Throwable;

final readonly class Failure
{
    public function __construct(
        public string $type,
        public string $message,
        public int|string $code,
    ) {}

    public static function fromThrowable(Throwable $throwable): self
    {
        return new self(type: $throwable::class, message: $throwable->getMessage(), code: $throwable->getCode());
    }
}
