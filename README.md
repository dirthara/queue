<p align="center">
  <img src="logo-no-bg.png" alt="Dirthara" width="480">
</p>

# Dirthara Queue

Queues for the Dirthara framework. This repository is the initial package scaffold; no public API or release is available yet. Usage 
documentation lives in [`docs`](docs/intro.md) and is published on the Dirthara documentation site at 
<https://dirthara.github.io/docs/>, which documents every package in the framework.

## Installation

Requires PHP `^8.5` (PHP 8.5 or a later PHP 8 release). Composer installs the runtime dependency, `dirthara/messaging`.
Install with:

```sh
composer require dirthara/queue
```

## Docker development environment

Requires Docker with Docker Compose. The development image provides PHP 8.5 CLI, Composer 2.10.3, Mago 1.47.3, and
Xdebug.

```sh
git clone git@github.com:dirthara/queue.git
cd queue
LOCAL_UID=$(id -u) LOCAL_GID=$(id -g) docker compose up -d --build php
docker compose exec php composer install
```

The container runs as the non-root `developer` user. The build arguments `LOCAL_UID` and `LOCAL_GID` default to 1000; 
the command above uses your host IDs so generated files remain editable. Set `PHP_VERSION` to override the default
8.5 image. Rebuild when the Dockerfile or build arguments change.

Open a shell or stop the environment with:

```sh
docker compose exec php bash
docker compose down
```

## Tests

```sh
docker compose exec php composer test
```

Tests belong in `tests`, under `Dirthara\Queue\Tests`. Source belongs in `src`, under `Dirthara\Queue`.

The package starts with its exception interface, `Dirthara\Queue\Exception\QueueException`, and the 
`HasExceptionContext` trait every exception uses to carry its context, both covered by tests.

## Code quality

Run the same checks as CI:

```sh
docker compose exec php composer ci
```

Run individual checks:

```sh
docker compose exec php composer fmt-check
docker compose exec php composer lint
docker compose exec php composer analyze
docker compose exec php composer guard
```

`composer mago` runs the formatting, import-order, lint, analysis, and configured architecture checks. `composer ci` 
also runs tooling tests, unit tests, and the coverage gate.

Apply formatting and import sorting with `composer fmt`, or include automatic lint fixes with `composer cs`:

```sh
docker compose exec php composer fmt
docker compose exec php composer cs
```

`composer cs` includes potentially unsafe lint fixes; review its changes.

Run coverage separately with:

```sh
docker compose exec php composer test-coverage
docker compose exec php composer coverage
```

Xdebug is inactive by default and enabled for the coverage run. The report is written to `build/coverage/clover.xml`. 
The gate requires 100% line coverage of `src` and lists uncovered lines.

## Contributing

Each supported version has its own branch, beginning with `0.1`; there is no `main`. See [CONTRIBUTING.md](CONTRIBUTING.md) for 
branching, release, and pull request requirements, and [AGENTS.md](AGENTS.md) for agent instructions.

## Security

Report vulnerabilities through GitHub's private advisory form. See [SECURITY.md](SECURITY.md) for the reporting process and 
scope.

## License

Copyright (c) 2026 Dirthara. Released under the [MIT License](LICENSE).
