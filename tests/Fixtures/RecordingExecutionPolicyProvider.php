<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Fixtures;

use Dirthara\Queue\ValueObject\MessageExecutionPolicy;
use Dirthara\Queue\Contract\MessageExecutionPolicyProvider;

final class RecordingExecutionPolicyProvider implements MessageExecutionPolicyProvider
{
    /**
     * @var list<object>
     */
    public private(set) array $asked = [];

    public function __construct(
        public readonly MessageExecutionPolicy $default,
        private readonly MessageExecutionPolicy $policy,
    ) {}

    public function policyFor(object $message): MessageExecutionPolicy
    {
        $this->asked[] = $message;

        return $this->policy;
    }
}
