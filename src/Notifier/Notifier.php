<?php

declare(strict_types=1);

namespace NetPulse\Notifier;

interface Notifier
{
    public function send(string $message): void;
}
