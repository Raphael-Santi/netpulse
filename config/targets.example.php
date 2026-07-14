<?php

declare(strict_types=1);

// Copy this file to config/targets.php and adjust for your network.
// Full reference: README.md.

return [
    // Any PDO DSN. SQLite needs zero configuration; for MySQL use e.g.
    // 'mysql:host=127.0.0.1;dbname=netpulse;charset=utf8mb4' plus user/password.
    'storage' => [
        'dsn' => 'sqlite:' . __DIR__ . '/../var/netpulse.db',
        'user' => '',
        'password' => '',
    ],

    // Consecutive failures required before an incident is opened.
    'failure_threshold' => 3,

    // Keep raw check results for this many days; older rows are purged
    // automatically on every monitoring cycle.
    'history_days' => 30,

    // Pause between rounds in watch mode, seconds.
    'interval' => 60,

    // Leave the token empty to print alerts to stderr instead of Telegram.
    'telegram' => [
        'token' => '',
        'chat_id' => '',
    ],

    'targets' => [
        ['name' => 'gateway', 'type' => 'ping', 'host' => '192.168.1.1'],
        ['name' => 'dns', 'type' => 'dns', 'host' => 'example.com', 'timeout' => 2.0],
        ['name' => 'site', 'type' => 'http', 'host' => 'example.com', 'tls' => true, 'path' => '/'],
        ['name' => 'db', 'type' => 'tcp', 'host' => '127.0.0.1', 'port' => 3306],
    ],
];
