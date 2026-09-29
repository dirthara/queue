---
id: failed-messages
title: Failed messages
sidebar_position: 8
description: Inspect, retry, and forget messages that failed for good.
---

A message fails for good when its [execution policy](execution-policies.md) decides not to retry it. A queue that
implements `FailedMessageRepository` keeps each such message, so it can be inspected and dealt with afterwards.
`InMemoryQueue` implements it.

## Inspect

```php
foreach ($queue->failed() as $failed) {
    printf(
        "%s  %s  attempt %d  %s\n",
        $failed->id,
        $failed->message->type,
        $failed->attempt,
        $failed->failure?->message ?? 'no failure given',
    );
}

$failed = $queue->findFailed($id);
```

`failed()` lists the failed messages in the order they failed; `findFailed()` returns one by its id, or `null` when no
failed message has that id. Each is a `FailedMessage`:

| Property | Type | Holds |
| --- | --- | --- |
| `id` | `string` | The identifier to retry or forget it by. |
| `message` | `QueuedMessage` | The message as it was queued: its type and serialised payload. |
| `attempt` | `int` | The attempt that failed, counted from 1. |
| `failure` | `?Failure` | A summary of why it failed, or `null` when it was failed without one. |

A `Failure` records the failure's class as `type`, its `message`, and its `code`, which is an `int` or, for exceptions
such as `PDOException`, a `string`. It keeps neither the exception object nor its previous exceptions.

:::caution
`Failure` keeps the exception message as it was. An exception message can contain data from the message being handled,
such as an email address, so treat failed messages with the same care as the queue itself.
:::

## Retry

```php
$queue->retry($failed->id);
```

`retry()` removes the message from the failed messages and queues it again behind the messages already waiting,
available straight away. It starts again at attempt 1, so its execution policy allows it the full number of attempts.
If it fails for good again, it is recorded under a new id.

## Forget

```php
$queue->forget($failed->id);
```

`forget()` removes a failed message for good, without delivering it again.

Retrying or forgetting an id that no failed message has, including one that was already retried or forgotten, throws a
`FailedMessageNotFoundException`.

## The contracts

Code that only reads failed messages depends on `FailedMessageProvider`, which has `failed()` and `findFailed()`. Code
that also retries and forgets depends on `FailedMessageRepository`, which extends it with `retry()` and `forget()`.
