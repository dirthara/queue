<?php

declare(strict_types=1);

namespace Dirthara\Queue;

enum WorkerOutcome: string
{
    case Idle = 'idle';
    case Handled = 'handled';
    case Released = 'released';
    case Failed = 'failed';
}
