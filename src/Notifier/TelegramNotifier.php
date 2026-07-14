<?php

declare(strict_types=1);

namespace NetPulse\Notifier;

/**
 * Delivers alerts through the Telegram Bot API. Delivery failures are
 * logged and swallowed on purpose: the monitor must keep monitoring
 * even when the alerting channel itself is down.
 */
final class TelegramNotifier implements Notifier
{
    private const float TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private readonly string $token,
        private readonly string $chatId,
    ) {
    }

    public function send(string $message): void
    {
        $url = sprintf('https://api.telegram.org/bot%s/sendMessage', $this->token);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query(['chat_id' => $this->chatId, 'text' => $message]),
                'timeout' => self::TIMEOUT_SECONDS,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            fwrite(STDERR, '[netpulse] telegram delivery failed' . PHP_EOL);
        }
    }
}
