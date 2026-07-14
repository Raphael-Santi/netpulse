<?php

declare(strict_types=1);

namespace NetPulse\Check;

use InvalidArgumentException;
use NetPulse\Model\CheckResult;
use NetPulse\Model\Target;

/**
 * Probes a TCP port by completing a full three-way handshake via
 * stream_socket_client(). Unlike ICMP ping this requires no privileges
 * and answers the question that actually matters for a service:
 * "is something accepting connections on this port?".
 */
final class TcpConnectCheck implements Check
{
    public function check(Target $target): CheckResult
    {
        if ($target->port === null) {
            throw new InvalidArgumentException('TCP check requires a port.');
        }

        $start = hrtime(true);
        $errno = 0;
        $errstr = '';

        $client = @stream_socket_client(
            sprintf('tcp://%s:%d', $target->host, $target->port),
            $errno,
            $errstr,
            $target->timeout,
        );

        if ($client === false) {
            $reason = $errstr !== '' ? $errstr : 'connection timed out';

            return CheckResult::down(sprintf('connect failed: %s (errno %d)', $reason, $errno));
        }

        $latencyMs = (hrtime(true) - $start) / 1_000_000;
        fclose($client);

        return CheckResult::up($latencyMs);
    }
}
