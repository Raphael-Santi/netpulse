<?php

declare(strict_types=1);

namespace NetPulse\Model;

final readonly class CheckOutcome
{
    public function __construct(
        public Target $target,
        public CheckResult $result,
    ) {
    }
}
