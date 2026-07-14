<?php

declare(strict_types=1);

namespace NetPulse\Tests\Storage;

use DateTimeImmutable;
use NetPulse\Model\CheckResult;
use NetPulse\Model\CheckStatus;
use NetPulse\Model\CheckType;
use NetPulse\Model\Target;
use NetPulse\Storage\PdoStorage;
use PHPUnit\Framework\TestCase;

final class PdoStorageTest extends TestCase
{
    private PdoStorage $storage;

    protected function setUp(): void
    {
        $this->storage = PdoStorage::fromDsn('sqlite::memory:');
        $this->storage->migrate();
    }

    public function testMigrateIsIdempotent(): void
    {
        $this->storage->migrate();
        $this->storage->migrate();

        self::assertSame([], $this->storage->openIncidents());
    }

    public function testSavesAndReadsBackResult(): void
    {
        $at = new DateTimeImmutable('2026-07-14 12:00:00');

        $this->storage->saveResult(self::target(), CheckResult::up(12.5), $at);

        $stored = $this->storage->lastResult('web');

        self::assertNotNull($stored);
        self::assertSame(CheckStatus::Up, $stored->status);
        self::assertSame(12.5, $stored->latencyMs);
        self::assertNull($stored->error);
        self::assertSame('2026-07-14 12:00:00', $stored->checkedAt->format('Y-m-d H:i:s'));
    }

    public function testLastResultIsNullForUnknownTarget(): void
    {
        self::assertNull($this->storage->lastResult('missing'));
    }

    public function testLastStatusesReturnsNewestFirstWithLimit(): void
    {
        $target = self::target();
        $at = new DateTimeImmutable('2026-07-14 12:00:00');

        $this->storage->saveResult($target, CheckResult::up(1.0), $at);
        $this->storage->saveResult($target, CheckResult::down('boom'), $at);
        $this->storage->saveResult($target, CheckResult::down('boom'), $at);

        self::assertSame(
            [CheckStatus::Down, CheckStatus::Down],
            $this->storage->lastStatuses('web', 2),
        );

        self::assertSame(
            [CheckStatus::Down, CheckStatus::Down, CheckStatus::Up],
            $this->storage->lastStatuses('web', 10),
        );
    }

    public function testIncidentLifecycle(): void
    {
        $openedAt = new DateTimeImmutable('2026-07-14 12:00:00');

        self::assertNull($this->storage->findOpenIncident('web'));

        $this->storage->openIncident('web', $openedAt);

        $incident = $this->storage->findOpenIncident('web');

        self::assertNotNull($incident);
        self::assertSame('web', $incident->targetName);
        self::assertSame('2026-07-14 12:00:00', $incident->openedAt->format('Y-m-d H:i:s'));
        self::assertCount(1, $this->storage->openIncidents());

        $this->storage->closeIncident($incident->id, $openedAt->modify('+30 minutes'));

        self::assertNull($this->storage->findOpenIncident('web'));
        self::assertSame([], $this->storage->openIncidents());
    }

    public function testDowntimeMinutes(): void
    {
        $openedAt = new DateTimeImmutable('2026-07-14 12:00:00');

        $this->storage->openIncident('web', $openedAt);

        $incident = $this->storage->findOpenIncident('web');

        self::assertNotNull($incident);
        self::assertSame(45, $incident->downtimeMinutes($openedAt->modify('+45 minutes')));
    }

    private static function target(): Target
    {
        return new Target(name: 'web', type: CheckType::Http, host: 'example.com');
    }
}
