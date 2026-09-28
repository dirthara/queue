<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests;

use PHPUnit\Framework\TestCase;
use Dirthara\Queue\InMemoryQueue;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\QueuedMessagePublisher;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmail;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmailSerializer;

final class QueuedMessagePublisherTest extends TestCase
{
    #[Test]
    public function it_enqueues_the_serialized_message(): void
    {
        $queue = new InMemoryQueue();
        $publisher = new QueuedMessagePublisher($queue, new SendWelcomeEmailSerializer());

        $publisher->publish(new SendWelcomeEmail('ada@example.com'));

        $delivery = $queue->reserve();

        self::assertNotNull($delivery);
        self::assertSame(SendWelcomeEmail::class, $delivery->message->type);
        self::assertSame('ada@example.com', $delivery->message->payload);
        self::assertNull($queue->reserve());
    }
}
