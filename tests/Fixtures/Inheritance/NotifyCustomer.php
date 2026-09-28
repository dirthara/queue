<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Fixtures\Inheritance;

readonly class NotifyCustomer
{
    public function __construct(
        public string $customer,
    ) {}
}
