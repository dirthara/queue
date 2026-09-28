<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests;

use RuntimeException;
use Dirthara\Queue\Worker;
use PHPUnit\Framework\TestCase;
use Dirthara\Queue\QueuedMessage;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\MessageHandlerRegistry;
use Dirthara\Queue\Driver\Memory\InMemoryQueue;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmail;
use Dirthara\Queue\Exception\MessageHandlerNotFoundException;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmailSerializer;

final class WorkerTest extends TestCase
{
    #[Test]
    public function it_does_nothing_when_the_queue_is_empty(): void
    {
        $worker = new Worker(new InMemoryQueue(), new SendWelcomeEmailSerializer(), new MessageHandlerRegistry());

        self::assertFalse($worker->runOnce());
    }

    #[Test]
    public function it_hands_the_deserialized_message_to_its_handler_and_acknowledges_it(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com'));

        $handled = [];
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use (&$handled): void {
            $handled[] = $message;
        });

        $worker = new Worker($queue, new SendWelcomeEmailSerializer(), $handlers);

        self::assertTrue($worker->runOnce());
        self::assertEquals([new SendWelcomeEmail('ada@example.com')], $handled);
        self::assertNull($queue->reserve());
    }

    #[Test]
    public function it_releases_the_message_and_rethrows_when_the_handler_fails(): void
    {
        $queue = new InMemoryQueue();
        $message = new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com');
        $queue->enqueue($message);

        $failure = new RuntimeException('The mail server is unavailable.');
        $handlers = new MessageHandlerRegistry();
        $handlers->register(SendWelcomeEmail::class, static function (object $message) use ($failure): void {
            throw $failure;
        });

        $worker = new Worker($queue, new SendWelcomeEmailSerializer(), $handlers);

        try {
            $worker->runOnce();
            self::fail('The handler failure was not rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame($message, $queue->reserve()?->message);
    }

    #[Test]
    public function it_rethrows_a_message_type_without_a_handler(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue(new QueuedMessage(SendWelcomeEmail::class, 'ada@example.com'));

        $worker = new Worker($queue, new SendWelcomeEmailSerializer(), new MessageHandlerRegistry());

        $this->expectException(MessageHandlerNotFoundException::class);

        $worker->runOnce();
    }
}
