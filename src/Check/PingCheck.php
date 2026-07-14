<?php

declare(strict_types=1);

namespace NetPulse\Check;

use NetPulse\Model\CheckResult;
use NetPulse\Model\Target;

/**
 * ICMP echo probe delegated to the system ping binary. Crafting raw ICMP
 * packets in PHP would require a raw socket and therefore root; the
 * iputils ping is granted that capability system-wide, so delegating is
 * the standard unprivileged approach.
 */
final class PingCheck implements Check
{
    public function __construct(private readonly CommandRunner $runner)
    {
    }

    public function check(Target $target): CheckResult
    {
        // -c 1: single echo request; -W: reply timeout in seconds (iputils).
        $timeoutSeconds = max(1, (int) ceil($target->timeout));

        $result = $this->runner->run([
            'ping', '-c', '1', '-W', (string) $timeoutSeconds, $target->host,
        ]);

        if ($result['exitCode'] !== 0) {
            return CheckResult::down(sprintf('ping failed with exit code %d', $result['exitCode']));
        }

        if (preg_match('/time=([\d.]+) ms/', $result['stdout'], $matches) === 1) {
            return CheckResult::up((float) $matches[1]);
        }

        // Reply received but latency not found in the output — still up.
        return CheckResult::up(0.0);
    }
}
