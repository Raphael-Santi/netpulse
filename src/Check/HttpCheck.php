<?php

declare(strict_types=1);

namespace NetPulse\Check;

use NetPulse\Model\CheckResult;
use NetPulse\Model\Target;

/**
 * HTTP(S) probe implemented directly on top of a TCP stream: the request
 * is written by hand and only the status line of the response is read.
 * No HTTP client dependency is needed for a health check, and the raw
 * socket keeps full control over connect and read timeouts. TLS targets
 * use the tls:// stream wrapper — the handshake happens transparently
 * during connect.
 */
final class HttpCheck implements Check
{
    private const int DEFAULT_HTTP_PORT = 80;
    private const int DEFAULT_HTTPS_PORT = 443;

    public function check(Target $target): CheckResult
    {
        $port = $target->port ?? ($target->tls ? self::DEFAULT_HTTPS_PORT : self::DEFAULT_HTTP_PORT);
        $scheme = $target->tls ? 'tls' : 'tcp';

        $start = hrtime(true);
        $errno = 0;
        $errstr = '';

        $client = @stream_socket_client(
            sprintf('%s://%s:%d', $scheme, $target->host, $port),
            $errno,
            $errstr,
            $target->timeout,
        );

        if ($client === false) {
            $reason = $errstr !== '' ? $errstr : 'connection timed out';

            return CheckResult::down(sprintf('connect failed: %s (errno %d)', $reason, $errno));
        }

        $seconds = (int) floor($target->timeout);
        $microseconds = (int) round(($target->timeout - $seconds) * 1_000_000);
        stream_set_timeout($client, $seconds, $microseconds);

        $hostHeader = $target->host;

        // RFC 9110: the Host header must include the port when it is not
        // the default one for the scheme — virtual hosts on non-standard
        // ports would otherwise route the request to the wrong site.
        if ($port !== ($target->tls ? self::DEFAULT_HTTPS_PORT : self::DEFAULT_HTTP_PORT)) {
            $hostHeader .= ':' . $port;
        }

        $request = sprintf(
            "GET %s HTTP/1.1\r\nHost: %s\r\nUser-Agent: netpulse/1.0\r\nConnection: close\r\n\r\n",
            $target->path,
            $hostHeader,
        );

        if (fwrite($client, $request) === false) {
            fclose($client);

            return CheckResult::down('failed to send request');
        }

        $statusLine = fgets($client);
        $meta = stream_get_meta_data($client);
        fclose($client);

        if ($statusLine === false || $meta['timed_out']) {
            return CheckResult::down('no response before timeout');
        }

        if (preg_match('#^HTTP/\d(?:\.\d)? (\d{3})#', $statusLine, $matches) !== 1) {
            return CheckResult::down(sprintf('malformed status line: %s', trim($statusLine)));
        }

        $code = (int) $matches[1];
        $latencyMs = (hrtime(true) - $start) / 1_000_000;

        if ($code >= 200 && $code < 400) {
            return CheckResult::up($latencyMs);
        }

        return CheckResult::down(sprintf('HTTP %d', $code));
    }
}
