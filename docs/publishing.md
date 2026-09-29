---
id: publishing
title: Publishing
sidebar_position: 4
description: Put messages on a queue, now or after a delay, and route message types to a queue.
---

## Publish a message

`QueuedMessagePublisher` serialises a message with a `MessageSerialiser` and enqueues the result:

```php
use Dirthara\Queue\QueuedMessagePublisher;

$publisher = new QueuedMessagePublisher($queue, $serialiser);

$publisher->publish(new SendWelcomeEmail('ada@example.com'));
```

The message is available to a worker straight away. `QueuedMessagePublisher` implements the `MessagePublisher` contract
of Dirthara Messaging, so code that publishes depends only on that contract and does not know the message is queued.

## Publish after a delay

`publishAfter()` holds a message back until a delay has passed:

```php
use Dirthara\Queue\ValueObject\Duration;

$publisher->publishAfter(new SendReminder($orderId), Duration::hours(24));
```

A delayed message is not delivered before its delay has passed; after that it is available like any other message.
Messages that are already available are delivered first, so a message published later without a delay can overtake a
delayed one. A delay of zero makes the message available straight away. The first delivery of a delayed message is
still attempt 1.

`publishAfter()` belongs to `DelayedMessagePublisher`, which extends `MessagePublisher`. Code that needs to delay a
message depends on `DelayedMessagePublisher`; code that only publishes keeps depending on `MessagePublisher`.

### Durations

A `Duration` is a whole, non-negative number of milliseconds, created from one unit:

| Factory | Example |
| --- | --- |
| `Duration::milliseconds(int)` | `Duration::milliseconds(1500)` |
| `Duration::seconds(int)` | `Duration::seconds(30)` |
| `Duration::minutes(int)` | `Duration::minutes(10)` |
| `Duration::hours(int)` | `Duration::hours(24)` |

A negative amount, or one too large to fit in a PHP integer of milliseconds, throws an `InvalidDurationException`. The
length is available as `$duration->milliseconds`.

## Route message types to a queue

Where a message goes is decided by routing, not by the message or its handler. With Dirthara Messaging's
`RoutingMessagePublisher`, each message type can be sent to its own queue, or to a destination that is not a queue at
all:

```php
use Dirthara\Messaging\RoutingMessagePublisher;
use Dirthara\Queue\QueuedMessagePublisher;

$mailQueue = new QueuedMessagePublisher($queueFactory->create($mailConfiguration), $serialiser);
$billingQueue = new QueuedMessagePublisher($queueFactory->create($billingConfiguration), $serialiser);

$publisher = new RoutingMessagePublisher();
$publisher->route(SendWelcomeEmail::class, $mailQueue);
$publisher->route(GenerateInvoice::class, $billingQueue);
```

`RoutingMessagePublisher` only publishes. To delay a message, call `publishAfter()` on the `QueuedMessagePublisher` for
its queue. See [queues and drivers](drivers.md) for creating queues from configuration.

## When publishing fails

A message the serialiser cannot serialise, such as a closure or an object of an anonymous class with
`NativeMessageSerialiser`, throws a `MessageSerialisationException`, and nothing is enqueued.
