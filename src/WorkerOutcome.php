<?php

declare(strict_types=1);

namespace Dirthara\Queue;

enum WorkerOutcome
{
    case Idle;
    case Handled;
    case Failed;
}
