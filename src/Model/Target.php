<?php

declare(strict_types=1);

namespace NetPulse\Model;

final readonly class Target
{
    public function __construct(
        public string $name,
        public CheckType $type,
        public string $host,
        public ?int $port = null,
        public float $timeout = 3.0,
        public string $path = '/',
        public bool $tls = false,
    ) {
    }

    public function describe(): string
    {
        $port = $this->port === null ? '' : ':' . $this->port;

        return sprintf('%s %s%s', $this->type->value, $this->host, $port);
    }
}
