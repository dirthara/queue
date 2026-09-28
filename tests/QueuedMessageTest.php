<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests;

use PHPUnit\Framework\TestCase;
use Dirthara\Queue\QueuedMessage;
use PHPUnit\Framework\Attributes\Test;

final class QueuedMessageTest extends TestCase
{
    #[Test]
    public function it_carries_a_type_and_a_payload(): void
    {
        $message = new QueuedMessage('App\\SendWelcomeEmail', '{"email":"ada@example.com"}');

        self::assertSame('App\\SendWelcomeEmail', $message->type);
        self::assertSame('{"email":"ada@example.com"}', $message->payload);
    }
}
