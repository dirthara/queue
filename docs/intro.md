---
id: intro
title: Dirthara Queue
sidebar_position: 1
description: Transport-neutral message queues and workers for PHP and the Dirthara framework.
---

Dirthara Queue hands messages to a queue and processes them later in a worker. A message is a plain PHP object: the
package serializes it onto a queue, and a worker takes it off again, passes it to the handler registered for its exact
class, and decides, when handling fails, whether to retry it, how long to wait first, or to fail it for good.

The package is transport-neutral. Its contracts describe a queue, a delivery, and a queue driver without assuming a
database, a broker, or a network; it ships an in-memory queue that implements them, for tests and for processing within
a single PHP process.

| Piece | Does | Read |
| --- | --- | --- |
| `QueuedMessagePublisher` | Serializes a message onto a queue, now or after a delay | [Publishing](publishing.md) |
| `MessageHandlerRegistry` | Maps each exact message class to the callable that processes it | [Handlers](handlers.md) |
| `MessageExecutionPolicyRegistry` | Chooses the retry and backoff behaviour per exact message class | [Execution policies](execution-policies.md) |
| `Worker` and `WorkerRunner` | Process one delivery, or keep processing until stopped or limited | [Workers](workers.md) |
| `FailedMessageRepository` | Lists, retries, and forgets messages that failed for good | [Failed messages](failed-messages.md) |
| `QueueDriverRegistry` | Creates a queue from a named driver and its configuration | [Queues and drivers](drivers.md) |
| `NativeMessageSerializer` | Turns a message into a payload and back | [Serialization](serialization.md) |

```php
use Dirthara\Queue\Driver\Memory\InMemoryQueue;
use Dirthara\Queue\MessageHandlerRegistry;
use Dirthara\Queue\QueuedMessagePublisher;
use Dirthara\Queue\Serializer\NativeMessageSerializer;

$queue = new InMemoryQueue();
$serializer = new NativeMessageSerializer();

$publisher = new QueuedMessagePublisher($queue, $serializer);
$publisher->publish(new SendWelcomeEmail('ada@example.com'));

$handlers = new MessageHandlerRegistry();
$handlers->register(SendWelcomeEmail::class, static function (SendWelcomeEmail $message) use ($mailer): void {
    $mailer->sendWelcomeEmail($message->email);
});
```

[Getting started](getting-started.md) builds this into a complete worker. See [installation](installation.md) for the
requirements, and [exceptions](exceptions.md) for every failure the package reports.

The package publishes through the `MessagePublisher` contract of
[Dirthara Messaging](https://dirthara.github.io/docs/), so code that publishes a message does not know it is queued.
