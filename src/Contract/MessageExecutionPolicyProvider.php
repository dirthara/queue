<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

use Dirthara\Queue\ValueObject\MessageExecutionPolicy;

interface MessageExecutionPolicyProvider
{
    public MessageExecutionPolicy $default { get; }

    public function policyFor(object $message): MessageExecutionPolicy;
}
