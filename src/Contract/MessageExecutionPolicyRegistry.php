<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

use Dirthara\Queue\ValueObject\MessageExecutionPolicy;

interface MessageExecutionPolicyRegistry extends MessageExecutionPolicyProvider
{
    /**
     * @param class-string $message
     */
    public function register(string $message, MessageExecutionPolicy $policy): void;
}
