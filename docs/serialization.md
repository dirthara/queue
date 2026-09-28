---
id: serialization
title: Serialization
sidebar_position: 10
description: How Dirthara Queue turns messages into payloads, and what the native serializer trusts.
---

A queue stores a `QueuedMessage`: the message's type and a string payload. A `MessageSerializer` turns a message into
one when it is published, and back into the message when a worker takes it off the queue.

## The native serializer

`NativeMessageSerializer` uses PHP's `serialize()` and `unserialize()`. The type it records is the message's exact
class, and deserializing refuses a payload that is malformed, holds something other than an object, or holds an object
of another class than the recorded type. Every failure is a `MessageSerializationException`, which never contains the
payload.

:::danger
Only use `NativeMessageSerializer` with a queue that nothing untrusted can write to.

Deserializing lets a payload create an object of any class the application has loaded. That class's
`__unserialize()`, `__wakeup()`, and `__destruct()` methods run before the serializer can check the type and reject
the payload, so anyone who can write a forged payload to the queue can run code in those methods. This is PHP object
injection.

The serializer restores every class on purpose, so that objects inside a message, such as a `DateTimeImmutable`
property or a value object, are restored with it. Restricting it to the declared message type would close this route,
and would break those messages. The queue is therefore part of the application's trust boundary: protect write access to
it as you would protect the application's code.
:::

A class that throws an `Error` while it is being restored, such as `DateTimeImmutable` given corrupt data, is not
wrapped in a `MessageSerializationException`; the `Error` escapes `deserialize()` as it is. A [worker](workers.md)
still catches it and settles the delivery with the default [execution policy](execution-policies.md).

Implement `MessageSerializer` to use another format, such as JSON with an explicit mapping per message type. A
serializer records the message type in the `QueuedMessage` it returns, and restores an object of exactly that type.
