<?php

declare(strict_types=1);

namespace NetPulse\Tests\Check;

use NetPulse\Check\CommandRunner;
use NetPulse\Check\PingCheck;
use NetPulse\Model\CheckType;
use NetPulse\Model\Target;
use PHPUnit\Framework\TestCase;

final class PingCheckTest extends TestCase
{
    public function testParsesLatencyFromPingOutput(): void
    {
        $runner = new FakeCommandRunner(
            exitCode: 0,
            stdout: "PING 8.8.8.8 (8.8.8.8) 56(84) bytes of data.\n"
                . "64 bytes from 8.8.8.8: icmp_seq=1 ttl=117 time=23.4 ms\n",
        );

        $result = (new PingCheck($runner))->check(self::target());

        self::assertTrue($result->isUp());
        self::assertSame(23.4, $result->latencyMs);
    }

    public function testNonZeroExitCodeMeansDown(): void
    {
        $runner = new FakeCommandRunner(exitCode: 1, stdout: '');

        $result = (new PingCheck($runner))->check(self::target());

        self::assertFalse($result->isUp());
        self::assertSame('ping failed with exit code 1', $result->error);
    }

    public function testBuildsCommandWithoutShell(): void
    {
        $runner = new FakeCommandRunner(exitCode: 0, stdout: '');

        (new PingCheck($runner))->check(self::target(timeout: 1.5));

        self::assertSame(['ping', '-c', '1', '-W', '2', '8.8.8.8'], $runner->lastCommand);
    }

    private static function target(float $timeout = 3.0): Target
    {
        return new Target(name: 'gw', type: CheckType::Ping, host: '8.8.8.8', timeout: $timeout);
    }
}

final class FakeCommandRunner implements CommandRunner
{
    /** @var list<string> */
    public array $lastCommand = [];

    public function __construct(
        private readonly int $exitCode,
        private readonly string $stdout,
    ) {
    }

    public function run(array $command): array
    {
        $this->lastCommand = $command;

        return ['exitCode' => $this->exitCode, 'stdout' => $this->stdout];
    }
}
