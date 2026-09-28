---
id: drivers
title: Queues and drivers
sidebar_position: 9
description: The queue and delivery contracts, the in-memory queue, and creating queues from driver configuration.
---

## The queue contract

A `Queue` has two operations:

| Method | Does |
| --- | --- |
| `enqueue(QueuedMessage $message, ?Duration $delay = null)` | Stores a message, available straight away or after the delay. |
| `reserve(): ?Delivery` | Takes the next available message off the queue, or returns `null` when none is available. |

A reserved message is not delivered again while it is reserved. The `Delivery` that `reserve()` returns carries the
queued `message` and the `attempt` it is, counted from 1, and is settled exactly once:

| Method | Settles the delivery by |
| --- | --- |
| `acknowledge()` | Removing the message, because it was handled. |
| `release(?Duration $duration = null)` | Queueing it again as the next attempt, available straight away or after the duration. |
| `fail(?Throwable $throwable = null)` | Removing it for good, recording why when the queue keeps failed messages. |

The [worker](workers.md) settles every delivery it reserves, so application code rarely calls these itself. The
in-memory queue throws a `DeliveryAlreadySettledException` when a delivery is settled a second time.

## The in-memory queue

`InMemoryQueue` keeps its messages in the PHP process. It suits tests, and work that is published and processed within
the same process:

- It delivers messages in the order they were enqueued or released, skipping any whose delay has not passed yet.
- It keeps failed messages and implements `FailedMessageRepository`; see [failed messages](failed-messages.md).
- Its messages are lost when the process ends, and other processes cannot see them.

It reads the current time whenever it needs it. For tests that control time, pass a closure that returns it:

```php
use Dirthara\Queue\Driver\Memory\InMemoryQueue;

$now = new DateTimeImmutable('2026-01-01 12:00:00');
$queue = new InMemoryQueue(static function () use (&$now): DateTimeImmutable {
    return $now;
});

$queue->enqueue($message, Duration::seconds(30));
$now = $now->modify('+30 seconds');
```

## Drivers

A `QueueDriver` creates a queue from a `QueueConfiguration`. A `QueueDriverRegistry` holds drivers by name and creates
a queue with the driver a configuration names:

```php
use Dirthara\Queue\Config\QueueConfiguration;
use Dirthara\Queue\Driver\Memory\MemoryQueueDriver;
use Dirthara\Queue\Driver\QueueDriverRegistry;

$drivers = new QueueDriverRegistry();
$drivers->register('memory', new MemoryQueueDriver());

$queue = $drivers->create(new QueueConfiguration('memory'));
```

Driver names are matched exactly, including their letter case. Registering a second driver under a name throws a
`DuplicateQueueDriverException` and keeps the first; asking for a name without a driver throws a
`QueueDriverNotFoundException`. `has()` tells whether a name has a driver.

The registry implements three contracts, so code can depend on only what it uses: `QueueFactory` for `create()`,
`QueueDriverProvider` for `has()` and `driver()`, and `QueueDriverRegistry`, which adds `register()`.

`MemoryQueueDriver` ignores the configuration's options and creates a new, separate `InMemoryQueue` each time.

## Configuration

A `QueueConfiguration` names a driver and carries the options for it:

```php
$configuration = new QueueConfiguration('redis', [
    'host' => 'localhost',
    'port' => 6379,
    'tls' => false,
]);
```

A driver reads its options with typed accessors:

| Method | Returns |
| --- | --- |
| `string(string $key, ?string $default = null)` | The option, which has to be a string. |
| `int(string $key, ?int $default = null)` | The option, which has to be an integer. |
| `bool(string $key, ?bool $default = null)` | The option, which has to be a boolean. |
| `has(string $key)` | Whether the option is present, even when its value is `null`. |

The default is used only when the option is missing. A missing option without a default, or a present option of
another type, throws an `InvalidQueueConfigurationException`. Values are never converted: the string `'6379'` is not an
integer, and a present `null` is not a missing option. The exception names the option and the type it has, but never
contains its value, because an option can hold a credential.
