<?php

declare(strict_types=1);

namespace Dirthara\Queue\Serialiser;

use Exception;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Queue\Contract\MessageSerialiser;
use Dirthara\Queue\Exception\MessageSerialisationException;

use function is_object;
use function serialize;
use function unserialize;
use function get_debug_type;
use function set_error_handler;
use function restore_error_handler;

final readonly class NativeMessageSerialiser implements MessageSerialiser
{
    /**
     * @throws MessageSerialisationException
     */
    public function serialise(object $message): QueuedMessage
    {
        try {
            $payload = serialize($message);
        } catch (Exception $exception) {
            throw MessageSerialisationException::unableToSerialise($message::class, $exception);
        }

        return new QueuedMessage(type: $message::class, payload: $payload);
    }

    /**
     * @throws MessageSerialisationException
     */
    public function deserialise(QueuedMessage $message): object
    {
        if ($message->payload === '') {
            throw MessageSerialisationException::unableToDeserialise($message->type);
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
            throw MessageSerialisationException::unableToDeserialise($message->type, $exception);
        } finally {
            restore_error_handler();
        }

        if ($malformed) {
            throw MessageSerialisationException::unableToDeserialise($message->type);
        }

        if (!is_object($value)) {
            throw MessageSerialisationException::notAnObject($message->type, get_debug_type($value));
        }

        if ($value::class !== $message->type) {
            throw MessageSerialisationException::typeMismatch($message->type, $value::class);
        }

        return $value;
    }
}
