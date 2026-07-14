<?php

declare(strict_types=1);

namespace NetPulse\Tests\Incident;

use DateTimeImmutable;
use NetPulse\Incident\IncidentDetector;
use NetPulse\Model\CheckResult;
use NetPulse\Model\CheckType;
use NetPulse\Model\Target;
use NetPulse\Notifier\Notifier;
use NetPulse\Storage\PdoStorage;
use PHPUnit\Framework\TestCase;

final class IncidentDetectorTest extends TestCase
{
    private PdoStorage $storage;
    private SpyNotifier $notifier;
    private IncidentDetector $detector;
    private Target $target;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->storage = PdoStorage::fromDsn('sqlite::memory:');
        $this->storage->migrate();

        $this->notifier = new SpyNotifier();
        $this->detector = new IncidentDetector($this->storage, $this->notifier, failureThreshold: 3);
        $this->target = new Target(name: 'web', type: CheckType::Http, host: 'example.com');
        $this->now = new DateTimeImmutable('2026-07-14 12:00:00');
    }

    public function testOpensIncidentAfterThresholdConsecutiveFailures(): void
    {
        $this->probe(CheckResult::down('HTTP 500'));
        $this->probe(CheckResult::down('HTTP 500'));
        $this->probe(CheckResult::down('HTTP 500'));

        self::assertNotNull($this->storage->findOpenIncident('web'));
        self::assertCount(1, $this->notifier->messages);
        self::assertStringContainsString('DOWN: web', $this->notifier->messages[0]);
        self::assertStringContainsString('HTTP 500', $this->notifier->messages[0]);
    }

    public function testStaysQuietBelowThreshold(): void
    {
        $this->probe(CheckResult::down('HTTP 500'));
        $this->probe(CheckResult::down('HTTP 500'));

        self::assertNull($this->storage->findOpenIncident('web'));
        self::assertSame([], $this->notifier->messages);
    }

    public function testSuccessResetsFailureStreak(): void
    {
        $this->probe(CheckResult::down('HTTP 500'));
        $this->probe(CheckResult::down('HTTP 500'));
        $this->probe(CheckResult::up(10.0));
        $this->probe(CheckResult::down('HTTP 500'));
        $this->probe(CheckResult::down('HTTP 500'));

        self::assertNull($this->storage->findOpenIncident('web'));
        self::assertSame([], $this->notifier->messages);
    }

    public function testDoesNotAlertTwiceWhileIncidentIsOpen(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->probe(CheckResult::down('HTTP 500'));
        }

        self::assertCount(1, $this->notifier->messages);
        self::assertCount(1, $this->storage->openIncidents());
    }

    public function testClosesIncidentAndNotifiesOnRecovery(): void
    {
        $this->probe(CheckResult::down('HTTP 500'));
        $this->probe(CheckResult::down('HTTP 500'));
        $this->probe(CheckResult::down('HTTP 500'));

        $this->now = $this->now->modify('+30 minutes');
        $this->probe(CheckResult::up(15.0));

        self::assertNull($this->storage->findOpenIncident('web'));
        self::assertCount(2, $this->notifier->messages);
        self::assertStringContainsString('RECOVERED: web', $this->notifier->messages[1]);
        self::assertStringContainsString('30 min', $this->notifier->messages[1]);
    }

    public function testRecoveryWithoutIncidentIsSilent(): void
    {
        $this->probe(CheckResult::up(10.0));

        self::assertSame([], $this->notifier->messages);
    }

    private function probe(CheckResult $result): void
    {
        $this->storage->saveResult($this->target, $result, $this->now);
        $this->detector->evaluate($this->target, $result, $this->now);
    }
}

final class SpyNotifier implements Notifier
{
    /** @var list<string> */
    public array $messages = [];

    public function send(string $message): void
    {
        $this->messages[] = $message;
    }
}
