<?php

declare(strict_types=1);

namespace NetPulse\Config;

use NetPulse\Model\Target;

final readonly class Config
{
    /**
     * @param list<Target> $targets
     */
    public function __construct(
        public array $targets,
        public string $dsn,
        public ?string $dbUser,
        public ?string $dbPassword,
        public int $failureThreshold,
        public int $intervalSeconds,
        public int $historyDays,
        public ?string $telegramToken,
        public ?string $telegramChatId,
    ) {
    }
}
