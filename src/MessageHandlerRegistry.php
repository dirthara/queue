<?php

declare(strict_types=1);

namespace Dirthara\Queue;

use ReflectionClass;
use Dirthara\Queue\Contract\MessageHandlerProvider;
use Dirthara\Queue\Exception\InvalidMessageTypeException;
use Dirthara\Queue\Exception\MessageHandlerNotFoundException;
use Dirthara\Queue\Exception\DuplicateMessageHandlerException;

use function class_exists;
use function array_key_exists;
use function interface_exists;

final class MessageHandlerRegistry implements MessageHandlerProvider
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
        $type = self::exactType($message);

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

    /**
     * @return class-string
     *
     * @throws InvalidMessageTypeException
     */
    private static function exactType(string $message): string
    {
        if (interface_exists($message)) {
            throw InvalidMessageTypeException::interfaceType($message);
        }

        if (!class_exists($message)) {
            throw InvalidMessageTypeException::notAnObjectType($message);
        }

        $class = new ReflectionClass($message);

        if ($class->isAbstract()) {
            throw InvalidMessageTypeException::abstractClass($message);
        }

        return $class->getName();
    }
}
