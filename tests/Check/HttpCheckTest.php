<?php

declare(strict_types=1);

namespace NetPulse\Tests\Check;

use NetPulse\Check\HttpCheck;
use NetPulse\Model\CheckType;
use NetPulse\Model\Target;
use PHPUnit\Framework\TestCase;

final class HttpCheckTest extends TestCase
{
    public function testReportsHttp200AsUp(): void
    {
        [$process, $port] = $this->startServer(200);

        $result = (new HttpCheck())->check(self::target($port));

        proc_close($process);

        self::assertTrue($result->isUp());
        self::assertNotNull($result->latencyMs);
    }

    public function testReportsHttp500AsDown(): void
    {
        [$process, $port] = $this->startServer(500);

        $result = (new HttpCheck())->check(self::target($port));

        proc_close($process);

        self::assertFalse($result->isUp());
        self::assertSame('HTTP 500', $result->error);
    }

    public function testReportsConnectionRefusedAsDown(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server);

        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);

        $pos = strrpos($address, ':');
        self::assertNotFalse($pos);

        $port = (int) substr($address, $pos + 1);
        fclose($server);

        $result = (new HttpCheck())->check(self::target($port));

        self::assertFalse($result->isUp());
        self::assertNotNull($result->error);
        self::assertStringContainsString('connect failed', $result->error);
    }

    private static function target(int $port): Target
    {
        return new Target(
            name: 'web',
            type: CheckType::Http,
            host: '127.0.0.1',
            port: $port,
            timeout: 3.0,
        );
    }

    /**
     * Launches the one-shot fixture server and waits for it to report
     * the port it bound to.
     *
     * @return array{0: resource, 1: int}
     */
    private function startServer(int $statusCode): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../fixtures/http_server.php', (string) $statusCode],
            [1 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($process);

        $stdout = $pipes[1] ?? null;
        self::assertIsResource($stdout);

        $line = fgets($stdout);
        self::assertIsString($line);

        return [$process, (int) trim($line)];
    }
}
