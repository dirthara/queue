<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

interface FailedMessageRepository extends FailedMessageProvider
{
    public function retry(string $id): void;

    public function forget(string $id): void;
}
