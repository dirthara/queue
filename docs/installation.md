---
id: installation
title: Installation
sidebar_position: 2
description: Requirements and installation status for Dirthara Queue.
---

## Requirements

PHP 8.5 or later within the PHP 8 series is required. Composer installs its one
runtime dependency:

| Package | Provides |
| --- | --- |
| `dirthara/messaging` `^0.1.0` | The `MessagePublisher` contract that `QueuedMessagePublisher` implements. |

## Package installation

Once published, install the package using Composer:

```sh
composer require dirthara/queue
```

:::caution
There is no published release yet. The command above describes the intended
installation after publication.
:::

For development, follow the Docker and Composer setup in the repository's
[README](https://github.com/dirthara/queue#readme). Development tooling
includes PHPUnit, Mago, and Xdebug.
