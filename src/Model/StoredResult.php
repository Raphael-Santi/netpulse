<?php

declare(strict_types=1);

namespace NetPulse\Model;

use DateTimeImmutable;

final readonly class StoredResult
{
    public function __construct(
        public CheckStatus $status,
        public ?float $latencyMs,
        public ?string $error,
        public DateTimeImmutable $checkedAt,
    ) {
    }
}
