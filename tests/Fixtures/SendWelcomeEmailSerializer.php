<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Fixtures;

use RuntimeException;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Queue\Contract\MessageSerializer;

final class SendWelcomeEmailSerializer implements MessageSerializer
{
    public function serialize(object $message): QueuedMessage
    {
        if (!$message instanceof SendWelcomeEmail) {
            throw new RuntimeException('The fixture serializer only serializes SendWelcomeEmail.');
        }

        return new QueuedMessage(SendWelcomeEmail::class, $message->email);
    }

    public function deserialize(QueuedMessage $message): object
    {
        return new SendWelcomeEmail($message->payload);
    }
}
