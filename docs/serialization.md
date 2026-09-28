---
id: serialization
title: Serialization
sidebar_position: 3
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

Restricting deserialization to the declared message type would close this, but would also stop objects inside a
message, such as a `DateTimeImmutable` property, from being restored. Which trade-off the package makes is still open
and will be settled before the first release.
:::
