---
id: getting-started
title: Getting started
sidebar_position: 3
description: Publish a message onto a queue and process it with a worker, from start to finish.
---

This page wires every piece together: a queue, a serialiser, a publisher, handlers, execution policies, and a worker.
Each piece has its own page with the details.

## 1. A message

A message is a plain object that describes a responsibility being handed over. It carries data and nothing else:

```php
final readonly class SendWelcomeEmail
{
    public function __construct(
        public string $email,
    ) {}
}
```

## 2. A queue and a serialiser

```php
use Dirthara\Queue\Driver\Memory\InMemoryQueue;
use Dirthara\Queue\Serialiser\NativeMessageSerialiser;

$queue = new InMemoryQueue();
$serialiser = new NativeMessageSerialiser();
```

`InMemoryQueue` keeps its messages in the PHP process, which suits tests and work that is processed within one
process. See [queues and drivers](drivers.md). `NativeMessageSerialiser` uses PHP's own serialisation; read
[serialisation](serialisation.md) before using it with a queue that anything else can write to.

## 3. Publish

```php
use Dirthara\Queue\QueuedMessagePublisher;
use Dirthara\Queue\ValueObject\Duration;

$publisher = new QueuedMessagePublisher($queue, $serialiser);

$publisher->publish(new SendWelcomeEmail('ada@example.com'));
$publisher->publishAfter(new SendWelcomeEmail('grace@example.com'), Duration::minutes(10));
```

The first message is available straight away, the second after ten minutes. See [publishing](publishing.md).

## 4. Handlers and execution policies

```php
use Dirthara\Queue\Backoff\NoBackoffPolicy;
use Dirthara\Queue\MessageExecutionPolicyRegistry;
use Dirthara\Queue\MessageHandlerRegistry;
use Dirthara\Queue\Retry\AttemptsRetryPolicy;
use Dirthara\Queue\ValueObject\MessageExecutionPolicy;

$handlers = new MessageHandlerRegistry();
$handlers->register(SendWelcomeEmail::class, static function (SendWelcomeEmail $message) use ($mailer): void {
    $mailer->sendWelcomeEmail($message->email);
});

$policies = new MessageExecutionPolicyRegistry(new MessageExecutionPolicy(
    retry: new AttemptsRetryPolicy(3),
    backoff: new NoBackoffPolicy(),
));
```

A handler is registered for an exact message class; see [handlers](handlers.md). The execution policy registry starts
from a default that every message type uses until it is given its own; see
[execution policies](execution-policies.md).

## 5. A worker

```php
use Dirthara\Queue\Worker;
use Dirthara\Queue\WorkerRunner;

$worker = new Worker(
    queue: $queue,
    serialiser: $serialiser,
    handlers: $handlers,
    executionPolicies: $policies,
);

$result = $worker->runOnce();
```

`runOnce()` processes the next available message and returns a `WorkerResult` saying whether it was handled, failed,
or whether the queue had nothing available. A `WorkerRunner` keeps calling it:

```php
$runner = new WorkerRunner(
    worker: $worker,
    idleDelay: Duration::seconds(1),
);

$runner->run();
```

See [workers](workers.md) for observing results, limiting how long a run lasts, and stopping a runner.

## 6. Failures

A message whose handler keeps failing is retried as its execution policy allows and then failed for good. The in-memory
queue keeps failed messages, so they can be inspected, retried, or forgotten:

```php
foreach ($queue->failed() as $failed) {
    echo $failed->message->type, ': ', $failed->failure?->message, PHP_EOL;
}
```

See [failed messages](failed-messages.md).
