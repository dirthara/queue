---
id: exceptions
title: Exceptions
sidebar_position: 11
description: Every exception Dirthara Queue throws, and when.
---

Every exception the package throws implements `Dirthara\Queue\Exception\QueueException`, so one `catch` handles any
of them. Each also extends the SPL exception that fits it and carries a `context` array with the values that describe
the failure. Context and messages never contain a message payload or a configuration value.

```php
use Dirthara\Queue\Exception\QueueException;

try {
    $handlers->register($type, $handler);
} catch (QueueException $exception) {
    $logger->error($exception->getMessage(), $exception->context);
}
```

## Configuration

These are thrown while an application is being set up, and point to a mistake in its code or configuration.

| Exception | Extends | Thrown when |
| --- | --- | --- |
| `InvalidMessageTypeException` | `InvalidArgumentException` | A handler or execution policy is registered for an interface, an abstract class, or a name that is not a class or enum. |
| `DuplicateMessageHandlerException` | `InvalidArgumentException` | A second handler is registered for a message type. |
| `DuplicateMessageExecutionPolicyException` | `InvalidArgumentException` | A second execution policy is registered for a message type. |
| `DuplicateQueueDriverException` | `InvalidArgumentException` | A second driver is registered under a name. |
| `InvalidQueueConfigurationException` | `InvalidArgumentException` | An option is missing without a default, or has another type than the one read. |
| `InvalidDurationException` | `InvalidArgumentException` | A duration is negative, or too long to fit in a PHP integer of milliseconds. |
| `InvalidRetryPolicyException` | `InvalidArgumentException` | `AttemptsRetryPolicy` is given fewer than one attempt. |
| `InvalidWorkerLimitsException` | `InvalidArgumentException` | `WorkerLimits` is given fewer than one message, or a runtime of zero. |

## Running

| Exception | Extends | Thrown when |
| --- | --- | --- |
| `MessageSerialisationException` | `RuntimeException` | A message cannot be serialised, or a payload cannot be deserialised into the message type it records. |
| `MessageHandlerNotFoundException` | `RuntimeException` | A message's type has no handler. |
| `QueueDriverNotFoundException` | `RuntimeException` | A queue is created with, or a driver asked for, a name without a driver. |
| `FailedMessageNotFoundException` | `RuntimeException` | A failed message is retried or forgotten by an id that no failed message has. |
| `DeliveryAlreadySettledException` | `RuntimeException` | A delivery from the in-memory queue that was already acknowledged, released, or failed is settled again. |
| `WorkerAlreadyRunningException` | `RuntimeException` | `run()` is called on a runner that is already running. |
| `InvalidWorkerResultException` | `InvalidArgumentException` | A handled, released, or failed `WorkerResult` is created for an attempt below 1, which points to a queue driver counting attempts wrongly. |

A worker does not let a failure to deserialise or handle a message escape: it settles the delivery and reports the
failure in its [result](workers.md#worker-results). What escapes a worker is a failure of the queue itself, such as a
delivery that cannot be settled, and those exceptions come from the queue driver.
