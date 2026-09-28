---
id: workers
title: Workers
sidebar_position: 5
description: Running a worker continuously, and observing the result of every delivery it processes.
---

A `Worker` processes one delivery at a time: `runOnce()` reserves the next available message, handles it, settles it,
and returns a `WorkerResult`. A `WorkerRunner` calls the worker in a loop, sleeping for its idle delay whenever the
queue has nothing available, until `stop()` is called.

```php
use Dirthara\Queue\WorkerRunner;
use Dirthara\Queue\ValueObject\Duration;

$runner = new WorkerRunner(
    worker: $worker,
    idleDelay: Duration::seconds(1),
);

$runner->run();
```

## Worker results

Every call to `runOnce()` returns a `WorkerResult` with one of three outcomes:

| Outcome | `message` | `attempt` | `failure` |
| --- | --- | --- | --- |
| `WorkerOutcome::Idle` | `null` | `null` | `null` |
| `WorkerOutcome::Handled` | The `QueuedMessage` | The attempt, from 1 | `null` |
| `WorkerOutcome::Failed` | The `QueuedMessage` | The attempt, from 1 | The `Throwable` |

A failed result means the failure was settled according to the message's
[execution policy](execution-policies.md): the delivery was released for a retry or failed for good. A result carries
the queued message as it was stored, never the deserialized message object. When settling a delivery itself fails,
because the queue cannot acknowledge, release, or fail it, the exception escapes the worker instead of becoming a
result.

## Observing results

A `WorkerObserver` receives every result a runner produces, including idle ones, in the order they happen:

```php
use Dirthara\Queue\Contract\WorkerObserver;
use Dirthara\Queue\ValueObject\WorkerResult;
use Dirthara\Queue\WorkerOutcome;

final readonly class FailureLogger implements WorkerObserver
{
    public function __construct(
        private Logger $logger,
    ) {}

    public function observe(WorkerResult $result): void
    {
        if ($result->outcome === WorkerOutcome::Failed) {
            $this->logger->error($result->failure?->getMessage() ?? 'unknown', ['type' => $result->message?->type]);
        }
    }
}

$runner = new WorkerRunner(
    worker: $worker,
    idleDelay: Duration::seconds(1),
    observer: new FailureLogger($logger),
);
```

Observation is the integration point for logging, metrics, telemetry, and CLI output. The package does not prescribe a
logger, an event dispatcher, or a metrics library, and depends on none of them; an application or framework integration
adapts results to whichever it uses. Without an observer, the runner uses a `NullWorkerObserver`, which ignores every
result.

An observer runs inside the loop, so an exception it throws is not swallowed: it escapes `run()` like any other failure,
and the runner can be run again afterwards.
