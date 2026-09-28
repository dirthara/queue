<?php

declare(strict_types=1);

namespace Dirthara\Queue\Contract;

use Dirthara\Queue\Config\QueueConfiguration;

interface QueueFactory
{
    public function create(QueueConfiguration $configuration): Queue;
}
