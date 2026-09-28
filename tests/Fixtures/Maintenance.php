<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Fixtures;

enum Maintenance
{
    case PurgeExpiredSessions;
}
