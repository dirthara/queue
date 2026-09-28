<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Fixtures;

final readonly class GenerateInvoice
{
    public function __construct(
        public string $invoice,
    ) {}
}
