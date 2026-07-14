<?php

declare(strict_types=1);

namespace NetPulse\Storage;

use DateTimeImmutable;
use NetPulse\Model\CheckResult;
use NetPulse\Model\CheckStatus;
use NetPulse\Model\Incident;
use NetPulse\Model\StoredResult;
use NetPulse\Model\Target;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Persistence for check results and incidents on top of PDO. SQLite is
 * the zero-configuration default; MySQL is supported through the same
 * interface — the two dialects differ only in the schema DDL, which is
 * why migrate() branches on the driver name.
 *
 * All queries use prepared statements: values coming from the config
 * (host names, target names) never reach SQL as text.
 */
final class PdoStorage
{
    private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public static function fromDsn(string $dsn, ?string $user = null, ?string $password = null): self
    {
        return new self(new PDO($dsn, $user, $password));
    }

    public function migrate(): void
    {
        foreach ($this->schemaStatements() as $sql) {
            $this->pdo->exec($sql);
        }
    }

    public function saveResult(Target $target, CheckResult $result, DateTimeImmutable $at): void
    {
        $statement = $this->prepare(
            'INSERT INTO check_results (target_name, check_type, status, latency_ms, error, checked_at)
             VALUES (:name, :type, :status, :latency, :error, :checked_at)',
        );

        $statement->execute([
            'name' => $target->name,
            'type' => $target->type->value,
            'status' => $result->status->value,
            'latency' => $result->latencyMs,
            'error' => $result->error,
            'checked_at' => $at->format(self::DATETIME_FORMAT),
        ]);
    }

    /**
     * Statuses of the most recent results for a target, newest first.
     *
     * @return list<CheckStatus>
     */
    public function lastStatuses(string $targetName, int $limit): array
    {
        $statement = $this->prepare(
            'SELECT status FROM check_results WHERE target_name = :name ORDER BY id DESC LIMIT ' . max(1, $limit),
        );

        $statement->execute(['name' => $targetName]);

        $statuses = [];

        while (is_array($row = $statement->fetch(PDO::FETCH_ASSOC))) {
            $statuses[] = CheckStatus::from(self::stringField($row, 'status'));
        }

        return $statuses;
    }

    public function lastResult(string $targetName): ?StoredResult
    {
        $statement = $this->prepare(
            'SELECT status, latency_ms, error, checked_at FROM check_results
             WHERE target_name = :name ORDER BY id DESC LIMIT 1',
        );

        $statement->execute(['name' => $targetName]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return new StoredResult(
            status: CheckStatus::from(self::stringField($row, 'status')),
            latencyMs: self::nullableFloatField($row, 'latency_ms'),
            error: self::nullableStringField($row, 'error'),
            checkedAt: self::dateTimeField($row, 'checked_at'),
        );
    }

    public function findOpenIncident(string $targetName): ?Incident
    {
        $statement = $this->prepare(
            'SELECT id, target_name, opened_at FROM incidents
             WHERE target_name = :name AND closed_at IS NULL ORDER BY id DESC LIMIT 1',
        );

        $statement->execute(['name' => $targetName]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::incidentFromRow($row) : null;
    }

    /**
     * @return list<Incident>
     */
    public function openIncidents(): array
    {
        $statement = $this->prepare(
            'SELECT id, target_name, opened_at FROM incidents WHERE closed_at IS NULL ORDER BY opened_at',
        );

        $statement->execute();

        $incidents = [];

        while (is_array($row = $statement->fetch(PDO::FETCH_ASSOC))) {
            $incidents[] = self::incidentFromRow($row);
        }

        return $incidents;
    }

    public function openIncident(string $targetName, DateTimeImmutable $at): void
    {
        $statement = $this->prepare(
            'INSERT INTO incidents (target_name, opened_at, closed_at) VALUES (:name, :opened_at, NULL)',
        );

        $statement->execute([
            'name' => $targetName,
            'opened_at' => $at->format(self::DATETIME_FORMAT),
        ]);
    }

    public function closeIncident(int $id, DateTimeImmutable $at): void
    {
        $statement = $this->prepare('UPDATE incidents SET closed_at = :closed_at WHERE id = :id');

        $statement->execute([
            'closed_at' => $at->format(self::DATETIME_FORMAT),
            'id' => $id,
        ]);
    }

    /**
     * @return list<string>
     */
    private function schemaStatements(): array
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if (!is_string($driver)) {
            throw new RuntimeException('Cannot detect PDO driver.');
        }

        // MySQL has no CREATE INDEX IF NOT EXISTS, so its indexes are
        // declared inline; SQLite does not allow inline INDEX clauses,
        // so they are created as separate statements.
        return match ($driver) {
            'sqlite' => [
                'CREATE TABLE IF NOT EXISTS check_results (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    target_name VARCHAR(100) NOT NULL,
                    check_type VARCHAR(10) NOT NULL,
                    status VARCHAR(10) NOT NULL,
                    latency_ms DOUBLE PRECISION NULL,
                    error TEXT NULL,
                    checked_at DATETIME NOT NULL
                )',
                'CREATE INDEX IF NOT EXISTS idx_results_target ON check_results (target_name, id)',
                'CREATE TABLE IF NOT EXISTS incidents (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    target_name VARCHAR(100) NOT NULL,
                    opened_at DATETIME NOT NULL,
                    closed_at DATETIME NULL
                )',
                'CREATE INDEX IF NOT EXISTS idx_incidents_target ON incidents (target_name, closed_at)',
            ],
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS check_results (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    target_name VARCHAR(100) NOT NULL,
                    check_type VARCHAR(10) NOT NULL,
                    status VARCHAR(10) NOT NULL,
                    latency_ms DOUBLE PRECISION NULL,
                    error TEXT NULL,
                    checked_at DATETIME NOT NULL,
                    INDEX idx_results_target (target_name, id)
                ) ENGINE=InnoDB',
                'CREATE TABLE IF NOT EXISTS incidents (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    target_name VARCHAR(100) NOT NULL,
                    opened_at DATETIME NOT NULL,
                    closed_at DATETIME NULL,
                    INDEX idx_incidents_target (target_name, closed_at)
                ) ENGINE=InnoDB',
            ],
            default => throw new RuntimeException(sprintf('Unsupported PDO driver: %s', $driver)),
        };
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('Failed to prepare SQL statement.');
        }

        return $statement;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function incidentFromRow(array $row): Incident
    {
        return new Incident(
            id: self::intField($row, 'id'),
            targetName: self::stringField($row, 'target_name'),
            openedAt: self::dateTimeField($row, 'opened_at'),
        );
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function intField(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        throw new RuntimeException(sprintf('Unexpected value in column "%s".', $key));
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (!is_string($value)) {
            throw new RuntimeException(sprintf('Unexpected value in column "%s".', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function nullableStringField(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return $value === null ? null : self::stringField($row, $key);
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function nullableFloatField(array $row, string $key): ?float
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new RuntimeException(sprintf('Unexpected value in column "%s".', $key));
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function dateTimeField(array $row, string $key): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(self::DATETIME_FORMAT, self::stringField($row, $key));

        if ($parsed === false) {
            throw new RuntimeException(sprintf('Unexpected datetime in column "%s".', $key));
        }

        return $parsed;
    }
}
