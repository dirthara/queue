---
id: installation
title: Installation
sidebar_position: 2
description: Requirements and installation of Dirthara Queue.
---

## Requirements

PHP 8.5 or later within the PHP 8 series is required. Composer installs its one
runtime dependency:

| Package | Provides |
| --- | --- |
| `dirthara/messaging` `^0.1.0` | The `MessagePublisher` contract that `QueuedMessagePublisher` implements. |

## Package installation

Install the package with Composer:

```sh
composer require dirthara/queue
```

For development, follow the Docker and Composer setup in the repository's
[README](https://github.com/dirthara/queue#readme). Development tooling
includes PHPUnit, Mago, and Xdebug.
