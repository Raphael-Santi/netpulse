<?php

declare(strict_types=1);

namespace NetPulse\Tests;

use NetPulse\Check\CheckFactory;
use NetPulse\Incident\IncidentDetector;
use NetPulse\Model\CheckType;
use NetPulse\Model\Target;
use NetPulse\Monitor;
use NetPulse\Notifier\Notifier;
use NetPulse\Storage\PdoStorage;
use PHPUnit\Framework\TestCase;

/**
 * Integration test of the whole pipeline: real TCP checks against local
 * sockets, real SQLite storage, real incident detection.
 */
final class MonitorTest extends TestCase
{
    public function testRunOncePersistsResultsAndOpensIncidents(): void
    {
        // A listening socket gives a guaranteed-up target; binding and
        // closing another one gives a guaranteed-down port.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server);
        $openPort = self::portOf($server);

        $closed = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($closed);
        $closedPort = self::portOf($closed);
        fclose($closed);

        $storage = PdoStorage::fromDsn('sqlite::memory:');
        $storage->migrate();

        $notifier = new class () implements Notifier {
            /** @var list<string> */
            public array $messages = [];

            public function send(string $message): void
            {
                $this->messages[] = $message;
            }
        };

        $monitor = new Monitor(
            targets: [
                self::tcpTarget('alive', $openPort),
                self::tcpTarget('dead', $closedPort),
            ],
            checks: new CheckFactory(),
            storage: $storage,
            detector: new IncidentDetector($storage, $notifier, failureThreshold: 1),
            historyDays: 30,
        );

        $outcomes = $monitor->runOnce();

        fclose($server);

        self::assertCount(2, $outcomes);
        self::assertTrue($outcomes[0]->result->isUp());
        self::assertFalse($outcomes[1]->result->isUp());

        self::assertNotNull($storage->lastResult('alive'));
        self::assertNull($storage->findOpenIncident('alive'));
        self::assertNotNull($storage->findOpenIncident('dead'));

        self::assertCount(1, $notifier->messages);
        self::assertStringContainsString('DOWN: dead', $notifier->messages[0]);
    }

    private static function tcpTarget(string $name, int $port): Target
    {
        return new Target(
            name: $name,
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
