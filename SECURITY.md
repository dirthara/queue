# Security Policy

## Supported versions

| Version | Status |
| --- | --- |
| 0.1.x | Active |
| Older | Unsupported |

While the package is pre-1.0, only the latest release line receives fixes.

## Reporting a vulnerability

Report vulnerabilities privately using GitHub's
[Report a vulnerability](https://github.com/dirthara/queue/security/advisories/new)
form. Do not disclose vulnerabilities in public issues or pull requests.

Include the affected version or commit, PHP version, a minimal reproduction,
and the impact and conditions needed to trigger the issue. Maintainers will
acknowledge and assess the report. Confirmed fixes are published with an
advisory crediting the reporter unless they prefer otherwise.

## Scope

The package queues serialised messages, delivers them to a worker, and
settles each delivery by the message's execution policy. In scope are flaws in
that behaviour and in the package's development configuration, such as:

- a message delivered to a handler registered for another type, or a
  registration silently replacing an earlier one;
- a delivery acknowledged, released, or failed more than once, a message lost
  instead of retried or failed, or a failed message retried or forgotten by the
  wrong id;
- a payload restored into an object of another class than the type recorded
  with it;
- an exception message or context disclosing a message payload or a
  configuration value, or letting a message type or option name forge a log
  line.

Out of scope:

- **Forged payloads with `NativeMessageSerialiser`.** The serialiser restores
  any class a payload names, by design, and is only safe on a queue that
  nothing untrusted can write to. Object injection through a payload written by
  someone with write access to the queue is not a vulnerability in this
  package; see
  [serialisation](https://github.com/dirthara/queue/blob/0.1/docs/serialisation.md).
- **Data kept with failed messages.** A failed message keeps its payload and
  the failure's exception message as they are; protecting that data is the
  application's responsibility.
- **Transports.** The package ships only an in-memory queue. Security issues in
  other queue drivers belong to the package that provides them.

Bugs in PHP or third-party dependencies should also be reported upstream.
Application code and the sensitivity of data an application chooses to store
are the application's responsibility.
