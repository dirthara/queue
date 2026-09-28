<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Fixtures;

use Throwable;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\Contract\BackoffPolicy;

final class RecordingBackoffPolicy implements BackoffPolicy
{
    /**
     * @var list<array{delivery: Delivery, failure: Throwable}>
     */
    public private(set) array $asked = [];

    public function __construct(
        private readonly Duration $delay,
    ) {}

    public function delay(Delivery $delivery, Throwable $failure): Duration
    {
        $this->asked[] = ['delivery' => $delivery, 'failure' => $failure];

        return $this->delay;
    }
}
