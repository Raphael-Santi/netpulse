<?php

declare(strict_types=1);

namespace NetPulse\Notifier;

/**
 * Fallback channel used when Telegram is not configured: alerts go to
 * stderr, where journald picks them up under systemd.
 */
final class StderrNotifier implements Notifier
{
    public function send(string $message): void
    {
        fwrite(STDERR, '[alert] ' . $message . PHP_EOL);
    }
}
