<?php

declare(strict_types=1);

namespace Dirthara\Queue;

use ReflectionClass;
use Dirthara\Queue\Exception\InvalidMessageTypeException;

use function class_exists;
use function interface_exists;

/**
 * @internal
 */
final class MessageType
{
    /**
     * @return class-string
     *
     * @throws InvalidMessageTypeException
     */
    public static function exact(string $message): string
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
