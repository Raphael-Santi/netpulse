<?php

declare(strict_types=1);

namespace NetPulse\Check;

use NetPulse\Model\CheckType;

/**
 * Maps a check type from the config to its implementation. The match is
 * exhaustive over the enum: adding a new CheckType without wiring it
 * here becomes a compile-level error for PHPStan.
 */
final class CheckFactory
{
    public function create(CheckType $type): Check
    {
        return match ($type) {
            CheckType::Tcp => new TcpConnectCheck(),
            CheckType::Http => new HttpCheck(),
            CheckType::Dns => new DnsResolveCheck(),
            CheckType::Ping => new PingCheck(new SystemCommandRunner()),
        };
    }
}
