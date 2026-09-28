<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Fixtures\Inheritance;

abstract readonly class PaymentMessage
{
    public function __construct(
        public string $payment,
    ) {}
}
