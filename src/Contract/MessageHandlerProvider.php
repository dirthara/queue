<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

interface MessageHandlerProvider
{
    /**
     * @return callable(object): void
     */
    public function handlerFor(object $message): callable;
}
