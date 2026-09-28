<?php

declare(strict_types=1);

namespace Dirthara\Queue\Serializer;

use Exception;
use Dirthara\Queue\QueuedMessage;
use Dirthara\Queue\Contract\MessageSerializer;
use Dirthara\Queue\Exception\MessageSerializationException;

use function is_object;
use function serialize;
use function unserialize;
use function get_debug_type;
use function set_error_handler;
use function restore_error_handler;

/**
 * Serializes messages with PHP's native serialize() and unserialize().
 *
 * Only use this serializer with a queue that nothing untrusted can write to. Deserializing lets a payload instantiate
 * any loaded class, and that class's __unserialize(), __wakeup(), and __destruct() run before the type check rejects
 * it, so a forged payload can trigger object injection. Restricting allowed_classes to the declared type would prevent
 * this, but would also stop objects nested inside a message from being restored.
 *
 * To be resolved before the first release; see CONTRIBUTING.md, "Before the first release".
 */
final readonly class NativeMessageSerializer implements MessageSerializer
{
    /**
     * @throws MessageSerializationException
     */
    public function serialize(object $message): QueuedMessage
    {
        try {
            $payload = serialize($message);
        } catch (Exception $exception) {
            throw MessageSerializationException::unableToSerialize($message::class, $exception);
        }

        return new QueuedMessage(type: $message::class, payload: $payload);
    }

    /**
     * @throws MessageSerializationException
     */
    public function deserialize(QueuedMessage $message): object
    {
        // unserialize() returns false for an empty payload without reporting it.
        if ($message->payload === '') {
            throw MessageSerializationException::unableToDeserialize($message->type);
        }

        $malformed = false;

        // unserialize() reports a malformed or truncated payload, and trailing data after a valid one, as a warning.
        set_error_handler(static function () use (&$malformed): bool {
            $malformed = true;

            return true;
        });

        try {
            // @mago-expect analysis:mixed-assignment -- a payload can hold any value, which is narrowed below
            $value = unserialize($message->payload, ['allowed_classes' => true]);
        } catch (Exception $exception) {
            throw MessageSerializationException::unableToDeserialize($message->type, $exception);
        } finally {
            restore_error_handler();
        }

        if ($malformed) {
            throw MessageSerializationException::unableToDeserialize($message->type);
        }

        if (!is_object($value)) {
            throw MessageSerializationException::notAnObject($message->type, get_debug_type($value));
        }

        if ($value::class !== $message->type) {
            throw MessageSerializationException::typeMismatch($message->type, $value::class);
        }

        return $value;
    }
}
