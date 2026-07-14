<?php

declare(strict_types=1);

namespace NetPulse\Model;

final readonly class CheckResult
{
    private function __construct(
        public CheckStatus $status,
        public ?float $latencyMs,
        public ?string $error,
    ) {
    }

    public static function up(float $latencyMs): self
    {
        return new self(CheckStatus::Up, $latencyMs, null);
    }

    public static function down(string $error): self
    {
        return new self(CheckStatus::Down, null, $error);
    }

    public function isUp(): bool
    {
        return $this->status === CheckStatus::Up;
    }
}
