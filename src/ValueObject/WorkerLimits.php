<?php

declare(strict_types=1);

namespace Dirthara\Queue\ValueObject;

use Dirthara\Queue\Exception\InvalidWorkerLimitsException;

final readonly class WorkerLimits
{
    /**
     * @throws InvalidWorkerLimitsException
     */
    public function __construct(
        public ?int $maxMessages = null,
        public ?Duration $maxRuntime = null,
    ) {
        if ($maxMessages !== null && $maxMessages < 1) {
            throw InvalidWorkerLimitsException::tooFewMessages($maxMessages);
        }

        if ($maxRuntime !== null && $maxRuntime->milliseconds === 0) {
            throw InvalidWorkerLimitsException::noRuntime();
        }
    }
}
