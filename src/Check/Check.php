<?php

declare(strict_types=1);

namespace NetPulse\Check;

use NetPulse\Model\CheckResult;
use NetPulse\Model\Target;

/**
 * A single availability probe. Implementations must never throw on an
 * unreachable target — unavailability is a result, not an exception.
 */
interface Check
{
    public function check(Target $target): CheckResult;
}
