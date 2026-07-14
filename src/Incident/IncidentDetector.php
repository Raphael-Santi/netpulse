<?php

declare(strict_types=1);

namespace NetPulse\Incident;

use DateTimeImmutable;
use NetPulse\Model\CheckResult;
use NetPulse\Model\CheckStatus;
use NetPulse\Model\Target;
use NetPulse\Notifier\Notifier;
use NetPulse\Storage\PdoStorage;

/**
 * Turns raw check results into incidents. An incident opens only after
 * N consecutive failures (a single lost packet is not an outage) and
 * produces exactly one alert; recovery closes it with a second alert.
 * State lives in the database, so a restart of the monitor never loses
 * an open incident and never re-alerts on one already reported.
 */
final class IncidentDetector
{
    public function __construct(
        private readonly PdoStorage $storage,
        private readonly Notifier $notifier,
        private readonly int $failureThreshold,
    ) {
    }

    /**
     * Must be called after the result has been persisted, so the failure
     * window examined here already includes the current probe.
     */
    public function evaluate(Target $target, CheckResult $result, DateTimeImmutable $now): void
    {
        $open = $this->storage->findOpenIncident($target->name);

        if ($result->isUp()) {
            if ($open !== null) {
                $this->storage->closeIncident($open->id, $now);

                $this->notifier->send(sprintf(
                    '🟢 RECOVERED: %s (%s) is up again after %d min of downtime.',
                    $target->name,
                    $target->describe(),
                    $open->downtimeMinutes($now),
                ));
            }

            return;
        }

        if ($open !== null) {
            // Incident already tracked — no duplicate alerts.
            return;
        }

        $recent = $this->storage->lastStatuses($target->name, $this->failureThreshold);

        if (count($recent) < $this->failureThreshold) {
            return;
        }

        foreach ($recent as $status) {
            if ($status !== CheckStatus::Down) {
                return;
            }
        }

        $this->storage->openIncident($target->name, $now);

        $this->notifier->send(sprintf(
            '🔴 DOWN: %s (%s) — %s. %d consecutive failures.',
            $target->name,
            $target->describe(),
            $result->error ?? 'unknown error',
            $this->failureThreshold,
        ));
    }
}
