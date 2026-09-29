---
id: workers
title: Workers
sidebar_position: 7
description: Running a worker continuously, observing the result of every delivery, and limiting how long a run lasts.
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

The idle delay has to be at least 1 millisecond. A delay of zero would make an idle runner poll its queue without
pausing, keeping a CPU core busy, so the runner rejects it with an `InvalidWorkerRunnerException`. A zero `Duration`
stays valid elsewhere, such as publishing without a delay or retrying without a backoff.

## Worker results

Every call to `runOnce()` returns a `WorkerResult` with one of four outcomes:

| Outcome | Means | `message` | `attempt` | `failure` |
| --- | --- | --- | --- | --- |
| `WorkerOutcome::Idle` | The queue had no delivery available. | `null` | `null` | `null` |
| `WorkerOutcome::Handled` | The handler succeeded and the delivery was acknowledged. | The `QueuedMessage` | The attempt, from 1 | `null` |
| `WorkerOutcome::Released` | Processing failed, the [execution policy](execution-policies.md) allowed another attempt, and the delivery was released for it. | The `QueuedMessage` | The attempt, from 1 | The `Throwable` |
| `WorkerOutcome::Failed` | Processing failed, the execution policy allowed no further attempt, and the delivery was failed for good. | The `QueuedMessage` | The attempt, from 1 | The `Throwable` |

Processing fails when the payload cannot be deserialised, when no handler is registered for the message type, or when
the handler throws. A `Released` and a `Failed` result both carry that original failure; they differ in what happened
to the delivery afterwards. A result describes a settlement that succeeded: when the queue cannot acknowledge, release,
or fail a delivery, the exception escapes the worker instead of becoming a result.

A result carries the queued message as it was stored, never the deserialised message object. `WorkerOutcome` is a
string-backed enum, so `$result->outcome->value` is `idle`, `handled`, `released`, or `failed`, ready for a log line or
a metric label.

## Delivery guarantees

A worker acknowledges a delivery only after its handler has returned. If the process stops in between, the handler's
work has happened but the queue was never told, and a durable queue delivers the message again. Queues are therefore
at-least-once: a handler whose work must not be repeated, such as charging a card, has to cope with receiving the same
message twice. The `attempt` of a result counts queue deliveries, not how often the work took effect. See
[delivery guarantees](drivers.md#delivery-guarantees).

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
        $context = ['type' => $result->message?->type, 'attempt' => $result->attempt];

        match ($result->outcome) {
            WorkerOutcome::Released => $this->logger->warning('Retrying: ' . $result->failure?->getMessage(), $context),
            WorkerOutcome::Failed => $this->logger->error('Failed: ' . $result->failure?->getMessage(), $context),
            WorkerOutcome::Idle, WorkerOutcome::Handled => null,
        };
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

## Lifecycle limits

A long-running PHP process is usually recycled now and then, for example to release memory or to pick up a deployment.
`WorkerLimits` tells a runner when its current run should end:

```php
use Dirthara\Queue\ValueObject\WorkerLimits;

$runner = new WorkerRunner(
    worker: $worker,
    idleDelay: Duration::seconds(1),
    limits: new WorkerLimits(
        maxMessages: 1000,
        maxRuntime: Duration::hours(1),
    ),
);

$runner->run();
```

When either limit is reached, `run()` returns normally after the delivery in progress has been settled and observed.
Whatever supervises the process, such as a process manager, a container orchestrator, or a framework command, then
decides whether to start the worker again. Spawning, restarting, daemonising, signal handling, and worker pools are
outside this package.

- **`maxMessages`** counts handled, released, and failed deliveries, so every retried attempt of the same message
  counts. Idle polls do not count. It has to be at least 1.
- **`maxRuntime`** is the time elapsed since `run()` started, measured with a monotonic clock. It has to be longer than
  0 milliseconds. A message that is already being handled when the runtime passes is never interrupted: it finishes, is
  settled, and is observed, and the runner then stops without reserving another message. While idle, the runner sleeps
  no longer than the runtime that remains.
- **`new WorkerLimits()`**, the default, sets neither limit, and the runner keeps running until it is stopped.

Each call to `run()` starts counting messages and measuring runtime from zero, so a runner that stopped at a limit can
be run again.

## Stopping a runner

`stop()` ends the run that is in progress, after the current delivery has been settled and observed. It only affects a
runner that is running: calling `stop()` before `run()` has no effect, and the next `run()` starts normally. Calling
`run()` while the runner is already running throws a `WorkerAlreadyRunningException`.
