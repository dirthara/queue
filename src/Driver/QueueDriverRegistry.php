<?php

declare(strict_types=1);

namespace Dirthara\Queue\Driver;

use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\QueueDriver;
use Dirthara\Queue\Contract\QueueFactory;
use Dirthara\Queue\Config\QueueConfiguration;
use Dirthara\Queue\Exception\QueueDriverNotFoundException;
use Dirthara\Queue\Exception\DuplicateQueueDriverException;
use Dirthara\Queue\Contract\QueueDriverRegistry as QueueDriverRegistryContract;

use function array_key_exists;

final class QueueDriverRegistry implements QueueDriverRegistryContract, QueueFactory
{
    /** @var array<string, QueueDriver> */
    private array $drivers = [];

    /**
     * @throws DuplicateQueueDriverException
     */
    public function register(string $name, QueueDriver $driver): void
    {
        if (array_key_exists($name, $this->drivers)) {
            throw DuplicateQueueDriverException::alreadyRegistered($name);
        }

        $this->drivers[$name] = $driver;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->drivers);
    }

    /**
     * @throws QueueDriverNotFoundException
     */
    public function driver(string $name): QueueDriver
    {
        return $this->drivers[$name] ?? throw QueueDriverNotFoundException::for($name);
    }

    /**
     * @throws QueueDriverNotFoundException
     */
    public function create(QueueConfiguration $configuration): Queue
    {
        return $this->driver($configuration->driver)->create($configuration);
    }
}
