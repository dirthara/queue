<?php

declare(strict_types=1);

namespace Dirthara\Queue\ValueObject;

final readonly class QueuedMessage
{
    public function __construct(
        public string $type,
        public string $payload,
    ) {}
}
