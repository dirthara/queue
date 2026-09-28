<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Driver\Memory;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\ValueObject\Failure;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Queue\Driver\Memory\FailedMessage;

final class FailedMessageTest extends TestCase
{
    #[Test]
    public function it_carries_its_id_message_attempt_and_failure(): void
    {
        $message = new QueuedMessage('type', 'payload');
        $failure = Failure::fromThrowable(new RuntimeException('The handler failed.'));

        $failed = new FailedMessage('id', $message, 3, $failure);

        self::assertSame('id', $failed->id);
        self::assertSame($message, $failed->message);
        self::assertSame(3, $failed->attempt);
        self::assertSame($failure, $failed->failure);
    }

    #[Test]
    public function it_carries_no_failure_by_default(): void
    {
        self::assertNull(new FailedMessage('id', new QueuedMessage('type', 'payload'), 1)->failure);
    }
}
