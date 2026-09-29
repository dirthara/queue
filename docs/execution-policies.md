---
id: execution-policies
title: Execution policies
sidebar_position: 6
description: How a worker decides to retry, delay, or fail a message, per exact message type.
---

Four separate concerns meet when a message is queued, and each lives in its own place:

| Concern | Answers | Lives in |
| --- | --- | --- |
| The message | What responsibility, and which data, was handed over | The message object |
| Routing | Where the message goes | The message publisher and its routing |
| The execution policy | How a worker processes the message when handling it fails | A `MessageExecutionPolicy` |
| The handler | What processing the message actually does | The handler registered for the message type |

A message carries data and nothing else, and a handler does work and nothing else. Neither knows about attempts,
retries, delays, or queues. Where a message is published, and on which queue it waits, is decided by routing; an
execution policy never chooses a destination.

## The policy

A `MessageExecutionPolicy` combines the two decisions a worker makes after a failure:

- its `RetryPolicy` decides whether the message is attempted again or failed;
- its `BackoffPolicy` decides how long a retried message waits before it is delivered again.

A successfully handled message is acknowledged without consulting either.

### Retry policies

| Policy | Retries a failed message |
| --- | --- |
| `AttemptsRetryPolicy(int $maxAttempts = 3)` | Until it has been attempted `$maxAttempts` times in total, then fails it. At least 1; `1` never retries. |
| `NeverRetryPolicy` | Never: the first failure fails it for good. |
| `UnlimitedRetryPolicy` | Always, for as long as it keeps failing. |

An attempt is counted from 1, so `AttemptsRetryPolicy(3)` means the first attempt and two retries. A retry policy
receives the `Delivery` and the failure, so a custom `RetryPolicy` can also decide by the kind of failure.

### Backoff policies

| Policy | Delays a retry by |
| --- | --- |
| `NoBackoffPolicy` | Nothing: the message is available again straight away. |
| `FixedBackoffPolicy(Duration $duration)` | The same duration every time. |
| `ExponentialBackoffPolicy(Duration $initialDuration, Duration $maximumDuration)` | `$initialDuration` after the first attempt, doubling after each attempt, never more than `$maximumDuration`. |

With an initial duration of 1 second and a maximum of 1 minute, `ExponentialBackoffPolicy` delays the retries after
attempts 1, 2, 3, and 4 by 1, 2, 4, and 8 seconds, and from attempt 7 on by 1 minute. See
[publishing](publishing.md#durations) for creating a `Duration`.

## Default and per-message policies

A `MessageExecutionPolicyRegistry` starts from a required default policy, which applies to every message type without a
policy of its own. A message type is given its own policy by registering it:

```php
use Dirthara\Queue\MessageExecutionPolicyRegistry;
use Dirthara\Queue\Retry\AttemptsRetryPolicy;
use Dirthara\Queue\Retry\NeverRetryPolicy;
use Dirthara\Queue\Backoff\NoBackoffPolicy;
use Dirthara\Queue\Backoff\ExponentialBackoffPolicy;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\ValueObject\MessageExecutionPolicy;
use Dirthara\Queue\Worker;

$policies = new MessageExecutionPolicyRegistry(new MessageExecutionPolicy(
    retry: new AttemptsRetryPolicy(3),
    backoff: new NoBackoffPolicy(),
));

$policies->register(SendWebhook::class, new MessageExecutionPolicy(
    retry: new AttemptsRetryPolicy(5),
    backoff: new ExponentialBackoffPolicy(
        initialDuration: Duration::seconds(5),
        maximumDuration: Duration::minutes(5),
    ),
));

$policies->register(ChargePayment::class, new MessageExecutionPolicy(
    retry: new NeverRetryPolicy(),
    backoff: new NoBackoffPolicy(),
));

$worker = new Worker(
    queue: $queue,
    serialiser: $serialiser,
    handlers: $handlers,
    executionPolicies: $policies,
);
```

Here a `SendWebhook` is attempted up to five times with a growing delay, a `ChargePayment` is failed on its first
failure, and every other message is attempted up to three times without a delay.

A policy is registered for an exact concrete class or enum. It never applies to a subclass, a parent class, or an
interface the message implements, and registering an interface or an abstract class is rejected with an
`InvalidMessageTypeException`. A message type has at most one policy: registering a second one throws a
`DuplicateMessageExecutionPolicyException` and keeps the first.

The worker depends only on `MessageExecutionPolicyProvider`, which exposes the `default` policy and resolves the policy
for a message with `policyFor()`. `MessageExecutionPolicyRegistry` adds `register()` for the code that configures the
policies.

## Which policy settles a failure

A worker resolves the policy for a message as soon as the message has been deserialised, so the policy of the message
type settles every failure from that point on:

| Failure | Settled with |
| --- | --- |
| The payload cannot be deserialised | The default policy |
| No handler is registered for the message type | The policy for the message type |
| The handler fails | The policy for the message type |

A payload that cannot be deserialised has no message, and so no message type to look a policy up for. The worker settles
it with the default policy rather than guessing a type from the payload. Choose the default with that in mind: a
default of `UnlimitedRetryPolicy` retries such a payload for as long as it keeps failing.
