---
id: handlers
title: Handlers
sidebar_position: 5
description: Register the callable that processes each message type, matched by its exact class.
---

A handler is the callable that does the work a message asks for. A `MessageHandlerRegistry` holds one handler per
message type, and a worker asks it for the handler of each message it takes off the queue:

```php
use Dirthara\Queue\MessageHandlerRegistry;

$handlers = new MessageHandlerRegistry();

$handlers->register(SendWelcomeEmail::class, static function (SendWelcomeEmail $message) use ($mailer): void {
    $mailer->sendWelcomeEmail($message->email);
});

$handlers->register(GenerateInvoice::class, $invoices->generate(...));
```

A handler receives the deserialised message and returns nothing. It knows nothing about queues, attempts, retries, or
delays: whether a failure is retried is decided by the message's [execution policy](execution-policies.md). A handler
reports failure by throwing.

## Exact message types

A handler is registered for an exact class or enum, and is only used for messages of that exact class:

- A handler for a parent class is not used for its subclasses, and a handler for a subclass is not used for its parent.
- An interface or an abstract class cannot be registered, because no message has one as its exact class. Registering
  one, or a name that is not a class or enum at all, throws an `InvalidMessageTypeException`.
- The class name is matched however it was written: `\App\SendWelcomeEmail` and `app\sendwelcomeemail` register the
  same type as `App\SendWelcomeEmail`.
- A message type has at most one handler. Registering a second throws a `DuplicateMessageHandlerException` and keeps
  the first.

An enum is registered by its class, and its handler receives the enum case.

## A message without a handler

`handlerFor()` throws a `MessageHandlerNotFoundException` for a message whose type has no handler. A worker treats that
as a failed attempt and settles it with the message's execution policy, so the message is retried or failed rather than
lost.

The worker depends on the `MessageHandlerProvider` contract, which has only `handlerFor()`; `MessageHandlerRegistry` is
the implementation the package provides.
