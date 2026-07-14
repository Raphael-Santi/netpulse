<?php

declare(strict_types=1);

namespace NetPulse;

use DateTimeImmutable;
use NetPulse\Check\CheckFactory;
use NetPulse\Incident\IncidentDetector;
use NetPulse\Model\CheckOutcome;
use NetPulse\Model\Target;
use NetPulse\Storage\PdoStorage;

/**
 * One monitoring cycle: probe every target, persist the result, let the
 * incident detector react. Orchestration only — all decisions live in
 * the collaborators, which is what keeps them testable in isolation.
 */
final class Monitor
{
    /**
     * @param list<Target> $targets
     */
    public function __construct(
        private readonly array $targets,
        private readonly CheckFactory $checks,
        private readonly PdoStorage $storage,
        private readonly IncidentDetector $detector,
    ) {
    }

    /**
     * @return list<CheckOutcome>
     */
    public function runOnce(): array
    {
        $outcomes = [];

        foreach ($this->targets as $target) {
            $result = $this->checks->create($target->type)->check($target);
            $now = new DateTimeImmutable();

            $this->storage->saveResult($target, $result, $now);
            $this->detector->evaluate($target, $result, $now);

            $outcomes[] = new CheckOutcome($target, $result);
        }

        return $outcomes;
    }
}
