<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\QueuedMessagePublisher;
use Dirthara\Queue\Tests\Fixtures\TestClock;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Queue\Driver\Memory\InMemoryQueue;
use Dirthara\Messaging\Contract\MessagePublisher;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmail;
use Dirthara\Queue\Contract\DelayedMessagePublisher;
use Dirthara\Queue\Serialiser\NativeMessageSerialiser;
use Dirthara\Queue\Exception\MessageSerialisationException;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmailSerialiser;

final class QueuedMessagePublisherTest extends TestCase
{
    #[Test]
    public function it_enqueues_the_serialised_message(): void
    {
        $queue = new InMemoryQueue();
        $publisher = new QueuedMessagePublisher($queue, new SendWelcomeEmailSerialiser());

        $publisher->publish(new SendWelcomeEmail('ada@example.com'));

        $delivery = $queue->reserve();

        self::assertNotNull($delivery);
        self::assertSame(SendWelcomeEmail::class, $delivery->message->type);
        self::assertSame('ada@example.com', $delivery->message->payload);
        self::assertNull($queue->reserve());
    }

    #[Test]
    public function it_is_a_message_publisher_that_can_delay(): void
    {
        $publisher = new QueuedMessagePublisher(new InMemoryQueue(), new SendWelcomeEmailSerialiser());

        self::assertInstanceOf(MessagePublisher::class, $publisher);
        self::assertInstanceOf(DelayedMessagePublisher::class, $publisher);
    }

    #[Test]
    public function it_makes_a_published_message_available_straight_away(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));

        new QueuedMessagePublisher($queue, new SendWelcomeEmailSerialiser())->publish(
            new SendWelcomeEmail('ada@example.com'),
        );

        self::assertSame('ada@example.com', $queue->reserve()?->message->payload);
    }

    #[Test]
    public function it_holds_back_a_message_published_after_a_delay_until_the_delay_has_passed(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));
        $publisher = new QueuedMessagePublisher($queue, new SendWelcomeEmailSerialiser());

        $publisher->publishAfter(new SendWelcomeEmail('ada@example.com'), Duration::minutes(10));

        self::assertNull($queue->reserve());

        $clock->advance('+9 minutes +59 seconds');
        self::assertNull($queue->reserve());

        $clock->advance('+1 second');
        $delivery = $queue->reserve();

        self::assertNotNull($delivery);
        self::assertSame(SendWelcomeEmail::class, $delivery->message->type);
        self::assertSame('ada@example.com', $delivery->message->payload);
        self::assertSame(1, $delivery->attempt);
    }

    #[Test]
    public function it_makes_a_message_published_after_no_delay_available_straight_away(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));

        new QueuedMessagePublisher($queue, new SendWelcomeEmailSerialiser())->publishAfter(
            new SendWelcomeEmail('ada@example.com'),
            Duration::milliseconds(0),
        );

        self::assertNotNull($queue->reserve());
    }

    #[Test]
    public function it_lets_a_later_undelayed_message_overtake_a_delayed_one(): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));
        $publisher = new QueuedMessagePublisher($queue, new SendWelcomeEmailSerialiser());

        $publisher->publishAfter(new SendWelcomeEmail('later@example.com'), Duration::seconds(30));
        $publisher->publish(new SendWelcomeEmail('now@example.com'));

        self::assertSame('now@example.com', $queue->reserve()?->message->payload);
        self::assertNull($queue->reserve());
    }

    /**
     * @return iterable<string, array{callable(QueuedMessagePublisher, object): void}>
     */
    public static function publications(): iterable
    {
        yield 'published' => [
            static fn(QueuedMessagePublisher $publisher, object $message): null => $publisher->publish($message),
        ];
        yield 'published after a delay' => [
            static fn(QueuedMessagePublisher $publisher, object $message): null => $publisher->publishAfter(
                $message,
                Duration::seconds(5),
            ),
        ];
    }

    /**
     * @param callable(QueuedMessagePublisher, object): void $publish
     */
    #[Test]
    #[DataProvider('publications')]
    public function it_enqueues_nothing_when_the_message_cannot_be_serialised(callable $publish): void
    {
        $clock = new TestClock();
        $queue = new InMemoryQueue($clock->now(...));
        $publisher = new QueuedMessagePublisher($queue, new NativeMessageSerialiser());

        try {
            $publish($publisher, static function (): void {});
            self::fail('A message that cannot be serialised was published.');
        } catch (MessageSerialisationException) {
            $clock->advance('+1 minute');
            self::assertNull($queue->reserve());
        }
    }
}
