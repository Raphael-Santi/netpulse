<?php

declare(strict_types=1);

namespace NetPulse\Check;

use NetPulse\Model\CheckResult;
use NetPulse\Model\Target;

/**
 * Measures name resolution through the system resolver (gethostbyname),
 * which honours /etc/hosts and nsswitch.conf the same way real clients
 * do. The system resolver offers no per-query timeout control — an
 * accepted trade-off documented in the README.
 */
final class DnsResolveCheck implements Check
{
    public function check(Target $target): CheckResult
    {
        // An IP address needs no resolution — report success immediately.
        if (filter_var($target->host, FILTER_VALIDATE_IP) !== false) {
            return CheckResult::up(0.0);
        }

        $start = hrtime(true);
        $ip = gethostbyname($target->host);

        // gethostbyname() signals failure by returning its argument unchanged.
        if ($ip === $target->host) {
            return CheckResult::down('DNS resolution failed');
        }

        return CheckResult::up((hrtime(true) - $start) / 1_000_000);
    }
}
