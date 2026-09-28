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
        if ($message->payload === '') {
            throw MessageSerializationException::unableToDeserialize($message->type);
        }

        $malformed = false;

        set_error_handler(static function () use (&$malformed): bool {
            $malformed = true;

            return true;
        });

        try {
            // @mago-expect analysis:mixed-assignment A payload can hold any value, which the checks below narrow
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
