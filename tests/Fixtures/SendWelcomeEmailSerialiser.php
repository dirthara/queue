<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Fixtures;

use RuntimeException;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Queue\Contract\MessageSerialiser;

final class SendWelcomeEmailSerialiser implements MessageSerialiser
{
    public function serialise(object $message): QueuedMessage
    {
        if (!$message instanceof SendWelcomeEmail) {
            throw new RuntimeException('The fixture serialiser only serialises SendWelcomeEmail.');
        }

        return new QueuedMessage(SendWelcomeEmail::class, $message->email);
    }

    public function deserialise(QueuedMessage $message): object
    {
        return new SendWelcomeEmail($message->payload);
    }
}
