<?php

declare(strict_types=1);

namespace Merql\Database;

use mysqli;
use mysqli_result;
use mysqli_stmt;

/**
 * DatabaseConnection adapter for mysqli/mysqlnd.
 */
final class MysqliDatabaseConnection implements DatabaseConnection
{
    private string $lastInsertId = '0';

    public function __construct(
        private readonly mysqli $mysqli,
        string $charset = 'utf8mb4',
    ) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->mysqli->set_charset($charset);
    }

    public static function connect(
        string $host,
        string $database,
        string $username,
        string $password = '',
        int $port = 3306,
        string $charset = 'utf8mb4',
    ): self {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        return new self(
            new mysqli($host, $username, $password, $database, $port),
            $charset,
        );
    }

    public function mysqli(): mysqli
    {
        return $this->mysqli;
    }

    public function driverName(): string
    {
        return 'mysql';
    }

    public function execute(string $sql, array $params = []): int
    {
        if ($params === []) {
            $this->mysqli->query($sql);
            $this->rememberInsertId($this->mysqli->insert_id);

            return self::affectedRows($this->mysqli->affected_rows);
        }

        $stmt = $this->prepareAndExecute($sql, $params);
        $this->rememberInsertId($stmt->insert_id);

        return self::affectedRows($stmt->affected_rows);
    }

    public function query(string $sql, array $params = []): array
    {
        if ($params === []) {
            $result = $this->mysqli->query($sql);

            return $result instanceof mysqli_result ? $this->normalizeResult($result) : [];
        }

        $stmt = $this->prepareAndExecute($sql, $params);

        return $this->fetchStatementRows($stmt);
    }

    public function scalar(string $sql, array $params = []): ?string
    {
        $rows = $this->query($sql, $params);
        if ($rows === [] || $rows[0] === []) {
            return null;
        }

        $value = array_values($rows[0])[0];

        return $value !== null ? (string) $value : null;
    }

    public function beginTransaction(): void
    {
        $this->mysqli->begin_transaction();
    }

    public function commit(): void
    {
        $this->mysqli->commit();
    }

    public function rollBack(): void
    {
        $this->mysqli->rollback();
    }

    public function lastInsertId(): string
    {
        return $this->lastInsertId;
    }

    /**
     * @param list<scalar|null> $params
     */
    private function prepareAndExecute(string $sql, array $params): mysqli_stmt
    {
        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt instanceof mysqli_stmt) {
            throw new \RuntimeException('Failed to prepare mysqli statement.');
        }
        $values = array_values($params);
        $types = str_repeat('s', count($values));
        $boundValues = [];
        $paramRefs = [];

        foreach ($values as $i => $value) {
            $boundValues[$i] = $value;
            $paramRefs[$i] = &$boundValues[$i];
        }

        $stmt->bind_param($types, ...$paramRefs);
        $stmt->execute();

        return $stmt;
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    private function normalizeResult(mysqli_result $result): array
    {
        $rows = [];
        while (($row = $result->fetch_assoc()) !== null) {
            if ($row === false) {
                continue;
            }
            $rows[] = self::normalizeRow($row);
        }

        return $rows;
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    private function fetchStatementRows(mysqli_stmt $stmt): array
    {
        $metadata = $stmt->result_metadata();
        if ($metadata === false) {
            return [];
        }

        $fields = $metadata->fetch_fields();
        $values = [];
        $refs = [];
        foreach ($fields as $field) {
            $values[$field->name] = null;
            $refs[] = &$values[$field->name];
        }

        $stmt->bind_result(...$refs);

        $rows = [];
        while ($stmt->fetch()) {
            $row = [];
            foreach ($values as $column => $value) {
                $row[$column] = self::normalizeValue($value);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, scalar|null>
     */
    private static function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $column => $value) {
            $normalized[$column] = self::normalizeValue($value);
        }

        return $normalized;
    }

    private static function normalizeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private static function affectedRows(int|string $affectedRows): int
    {
        if (is_int($affectedRows)) {
            return max(0, $affectedRows);
        }

        if (!is_numeric($affectedRows)) {
            return 0;
        }

        return max(0, (int) $affectedRows);
    }

    private function rememberInsertId(int|string $insertId): void
    {
        $id = (string) $insertId;
        if ($id !== '0') {
            $this->lastInsertId = $id;
        }
    }
}
