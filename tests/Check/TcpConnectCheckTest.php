<?php

declare(strict_types=1);

namespace NetPulse\Tests\Check;

use NetPulse\Check\TcpConnectCheck;
use NetPulse\Model\CheckType;
use NetPulse\Model\Target;
use PHPUnit\Framework\TestCase;

final class TcpConnectCheckTest extends TestCase
{
    public function testReportsOpenPortAsUp(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server);

        $port = self::portOf($server);

        $result = (new TcpConnectCheck())->check(self::target($port));

        fclose($server);

        self::assertTrue($result->isUp());
        self::assertNotNull($result->latencyMs);
        self::assertGreaterThan(0.0, $result->latencyMs);
    }

    public function testReportsClosedPortAsDown(): void
    {
        // Bind and immediately release a socket to obtain a port that is
        // known to be free, then probe it.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server);

        $port = self::portOf($server);
        fclose($server);

        $result = (new TcpConnectCheck())->check(self::target($port));

        self::assertFalse($result->isUp());
        self::assertNotNull($result->error);
        self::assertStringContainsString('connect failed', $result->error);
    }

    private static function target(int $port): Target
    {
        return new Target(
            name: 'test',
            type: CheckType::Tcp,
            host: '127.0.0.1',
            port: $port,
            timeout: 1.0,
        );
    }

    /**
     * @param resource $server
     */
    private static function portOf($server): int
    {
        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);

        $pos = strrpos($address, ':');
        self::assertNotFalse($pos);

        return (int) substr($address, $pos + 1);
    }
}
