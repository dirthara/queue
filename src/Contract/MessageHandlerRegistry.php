<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

interface MessageHandlerRegistry extends MessageHandlerProvider
{
    /**
     * @param class-string $message
     */
    public function register(string $message, callable $handler): void;
}
