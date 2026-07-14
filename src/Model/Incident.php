<?php

declare(strict_types=1);

namespace NetPulse\Model;

use DateTimeImmutable;

final readonly class Incident
{
    public function __construct(
        public int $id,
        public string $targetName,
        public DateTimeImmutable $openedAt,
    ) {
    }

    public function downtimeMinutes(DateTimeImmutable $now): int
    {
        return max(0, (int) floor(($now->getTimestamp() - $this->openedAt->getTimestamp()) / 60));
    }
}
