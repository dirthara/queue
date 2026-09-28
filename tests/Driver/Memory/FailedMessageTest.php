<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Driver\Memory;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use Dirthara\Queue\QueuedMessage;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Driver\Memory\FailedMessage;

final class FailedMessageTest extends TestCase
{
    #[Test]
    public function it_carries_the_message_and_its_failure(): void
    {
        $message = new QueuedMessage('type', 'payload');
        $failure = new RuntimeException('The handler failed.');

        $failed = new FailedMessage($message, $failure);

        self::assertSame($message, $failed->message);
        self::assertSame($failure, $failed->failure);
    }

    #[Test]
    public function it_carries_no_failure_by_default(): void
    {
        self::assertNull(new FailedMessage(new QueuedMessage('type', 'payload'))->failure);
    }
}
