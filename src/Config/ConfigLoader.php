<?php

declare(strict_types=1);

namespace NetPulse\Config;

use InvalidArgumentException;
use NetPulse\Model\CheckType;
use NetPulse\Model\Target;

/**
 * Loads and validates the monitoring configuration from a PHP file
 * returning an array. Every value is checked before use so that a typo
 * in the config fails fast with a clear message instead of surfacing
 * as a confusing runtime error deep inside a check.
 */
final class ConfigLoader
{
    private const int DEFAULT_FAILURE_THRESHOLD = 3;
    private const int DEFAULT_INTERVAL_SECONDS = 60;
    private const int DEFAULT_HISTORY_DAYS = 30;
    private const float DEFAULT_TIMEOUT_SECONDS = 3.0;

    public static function load(string $path): Config
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Config file not found: %s', $path));
        }

        $raw = require $path;

        if (!is_array($raw)) {
            throw new InvalidArgumentException('Config file must return an array.');
        }

        $targetsRaw = $raw['targets'] ?? null;

        if (!is_array($targetsRaw) || $targetsRaw === []) {
            throw new InvalidArgumentException('Config must define a non-empty "targets" list.');
        }

        $targets = [];

        foreach (array_values($targetsRaw) as $index => $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException(sprintf('Target #%d must be an array.', $index));
            }

            $targets[] = self::parseTarget($item, $index);
        }

        $storage = self::arraySection($raw, 'storage');
        $telegram = self::arraySection($raw, 'telegram');

        $failureThreshold = self::intValue($raw, 'failure_threshold') ?? self::DEFAULT_FAILURE_THRESHOLD;
        $intervalSeconds = self::intValue($raw, 'interval') ?? self::DEFAULT_INTERVAL_SECONDS;
        $historyDays = self::intValue($raw, 'history_days') ?? self::DEFAULT_HISTORY_DAYS;

        if ($failureThreshold < 1 || $intervalSeconds < 1 || $historyDays < 1) {
            throw new InvalidArgumentException(
                '"failure_threshold", "interval" and "history_days" must be positive integers.',
            );
        }

        return new Config(
            targets: $targets,
            dsn: self::stringValue($storage, 'dsn') ?? 'sqlite:' . dirname($path) . '/../var/netpulse.db',
            dbUser: self::emptyToNull(self::stringValue($storage, 'user')),
            dbPassword: self::emptyToNull(self::stringValue($storage, 'password')),
            failureThreshold: $failureThreshold,
            intervalSeconds: $intervalSeconds,
            historyDays: $historyDays,
            telegramToken: self::emptyToNull(self::stringValue($telegram, 'token')),
            telegramChatId: self::emptyToNull(self::stringValue($telegram, 'chat_id')),
        );
    }

    /**
     * @param array<array-key, mixed> $item
     */
    private static function parseTarget(array $item, int $index): Target
    {
        $name = self::stringValue($item, 'name');
        $typeRaw = self::stringValue($item, 'type');
        $host = self::stringValue($item, 'host');

        if ($name === null || $name === '' || $typeRaw === null || $typeRaw === '' || $host === null || $host === '') {
            throw new InvalidArgumentException(
                sprintf('Target #%d requires non-empty string fields "name", "type" and "host".', $index),
            );
        }

        $type = CheckType::tryFrom($typeRaw);

        if ($type === null) {
            throw new InvalidArgumentException(
                sprintf('Target "%s" has unknown check type "%s".', $name, $typeRaw),
            );
        }

        $port = self::intValue($item, 'port');

        if ($type === CheckType::Tcp && $port === null) {
            throw new InvalidArgumentException(sprintf('Target "%s": tcp check requires a "port".', $name));
        }

        return new Target(
            name: $name,
            type: $type,
            host: $host,
            port: $port,
            timeout: self::floatValue($item, 'timeout') ?? self::DEFAULT_TIMEOUT_SECONDS,
            path: self::stringValue($item, 'path') ?? '/',
            tls: self::boolValue($item, 'tls') ?? false,
        );
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return array<array-key, mixed>
     */
    private static function arraySection(array $raw, string $key): array
    {
        $value = $raw[$key] ?? [];

        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('Config section "%s" must be an array.', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function stringValue(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Config key "%s" must be a string.', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function intValue(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('Config key "%s" must be an integer.', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function floatValue(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException(sprintf('Config key "%s" must be a number.', $key));
        }

        return (float) $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function boolValue(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_bool($value)) {
            throw new InvalidArgumentException(sprintf('Config key "%s" must be a boolean.', $key));
        }

        return $value;
    }

    private static function emptyToNull(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
