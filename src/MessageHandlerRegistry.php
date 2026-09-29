<?php

declare(strict_types=1);

namespace Dirthara\Queue;

use Dirthara\Queue\Exception\InvalidMessageTypeException;
use Dirthara\Queue\Exception\MessageHandlerNotFoundException;
use Dirthara\Queue\Exception\DuplicateMessageHandlerException;
use Dirthara\Queue\Contract\MessageHandlerRegistry as MessageHandlerRegistryContract;

use function array_key_exists;

final class MessageHandlerRegistry implements MessageHandlerRegistryContract
{
    /**
     * @var array<class-string, callable(object): void>
     */
    private array $handlers = [];

    /**
     * @param class-string $message
     *
     * @throws InvalidMessageTypeException
     * @throws DuplicateMessageHandlerException
     */
    public function register(string $message, callable $handler): void
    {
        $type = MessageType::exact($message);

        if (array_key_exists($type, $this->handlers)) {
            throw DuplicateMessageHandlerException::alreadyRegistered($type);
        }

        $this->handlers[$type] = $handler;
    }

    /**
     * @throws MessageHandlerNotFoundException
     */
    public function handlerFor(object $message): callable
    {
        return $this->handlers[$message::class] ?? throw MessageHandlerNotFoundException::noHandlerFor($message::class);
    }
}
