<?php

declare(strict_types=1);

namespace Merql\Database;

use PDO;

/**
 * DatabaseConnection adapter for PDO.
 */
final class PdoDatabaseConnection implements DatabaseConnection
{
    private const PDO_OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => true,
    ];

    public function __construct(private readonly PDO $pdo)
    {
        foreach (self::PDO_OPTIONS as $attribute => $value) {
            $this->pdo->setAttribute($attribute, $value);
        }
    }

    /**
     * @return array<int, mixed>
     */
    public static function options(): array
    {
        return self::PDO_OPTIONS;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driverName(): string
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if (!is_string($driver)) {
            throw new \RuntimeException('Unable to determine PDO driver name.');
        }

        return $driver;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public function query(string $sql, array $params = []): array
    {
        if ($params === []) {
            $stmt = $this->pdo->query($sql);
        } else {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        }

        if ($stmt === false) {
            return [];
        }

        return self::normalizeRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function scalar(string $sql, array $params = []): ?string
    {
        if ($params === []) {
            $stmt = $this->pdo->query($sql);
        } else {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        }

        if ($stmt === false) {
            return null;
        }

        $value = $stmt->fetchColumn();

        if ($value === false) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    public function lastInsertId(): string
    {
        $id = $this->pdo->lastInsertId();

        return $id !== false ? $id : '';
    }

    /**
     * @param mixed $rawRows
     * @return list<array<string, scalar|null>>
     */
    private static function normalizeRows(mixed $rawRows): array
    {
        if (!is_array($rawRows)) {
            return [];
        }

        $rows = [];
        foreach ($rawRows as $rawRow) {
            if (!is_array($rawRow)) {
                continue;
            }

            $row = [];
            foreach ($rawRow as $column => $value) {
                if (!is_string($column)) {
                    continue;
                }
                $row[$column] = is_scalar($value) || $value === null ? $value : null;
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
