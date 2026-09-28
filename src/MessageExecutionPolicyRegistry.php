<?php

declare(strict_types=1);

namespace Dirthara\Queue;

use Dirthara\Queue\ValueObject\MessageExecutionPolicy;
use Dirthara\Queue\Exception\InvalidMessageTypeException;
use Dirthara\Queue\Exception\DuplicateMessageExecutionPolicyException;
use Dirthara\Queue\Contract\MessageExecutionPolicyRegistry as MessageExecutionPolicyRegistryContract;

use function array_key_exists;

final class MessageExecutionPolicyRegistry implements MessageExecutionPolicyRegistryContract
{
    /**
     * @var array<class-string, MessageExecutionPolicy>
     */
    private array $policies = [];

    public function __construct(
        public readonly MessageExecutionPolicy $default,
    ) {}

    /**
     * @param class-string $message
     *
     * @throws InvalidMessageTypeException
     * @throws DuplicateMessageExecutionPolicyException
     */
    public function register(string $message, MessageExecutionPolicy $policy): void
    {
        $type = MessageType::exact($message);

        if (array_key_exists($type, $this->policies)) {
            throw DuplicateMessageExecutionPolicyException::alreadyRegistered($type);
        }

        $this->policies[$type] = $policy;
    }

    public function policyFor(object $message): MessageExecutionPolicy
    {
        return $this->policies[$message::class] ?? $this->default;
    }
}
