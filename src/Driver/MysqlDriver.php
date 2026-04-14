<?php

declare(strict_types=1);

namespace Merql\Driver;

use Merql\Schema\TableSchema;
use PDO;

/**
 * MySQL/MariaDB driver implementation.
 */
final class MysqlDriver implements Driver
{
    public function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function listTables(PDO $pdo): array
    {
        $db = $this->currentDatabase($pdo);
        $stmt = $pdo->prepare(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES '
            . 'WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = :type ORDER BY TABLE_NAME'
        );
        $stmt->execute(['db' => $db, 'type' => 'BASE TABLE']);

        /** @var list<string> */
        return array_values($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function readSchema(PDO $pdo, string $table): TableSchema
    {
        $db = $this->currentDatabase($pdo);

        $columns = $this->readColumns($pdo, $db, $table);
        $primaryKey = $this->readPrimaryKey($pdo, $db, $table);
        $uniqueKeys = $this->readUniqueKeys($pdo, $db, $table);

        return new TableSchema($table, $columns, $primaryKey, $uniqueKeys);
    }

    public function readForeignKeys(PDO $pdo): array
    {
        $db = $this->currentDatabase($pdo);
        $stmt = $pdo->prepare(
            'SELECT TABLE_NAME, REFERENCED_TABLE_NAME '
            . 'FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = :db '
            . 'AND REFERENCED_TABLE_NAME IS NOT NULL '
            . 'GROUP BY TABLE_NAME, REFERENCED_TABLE_NAME'
        );
        $stmt->execute(['db' => $db]);

        $deps = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $deps[$row['TABLE_NAME']][] = $row['REFERENCED_TABLE_NAME'];
        }

        return $deps;
    }

    public function selectAll(string $table): string
    {
        return 'SELECT * FROM ' . $this->quoteIdentifier($table);
    }

    /**
     * @return array<string, string>
     */
    private function readColumns(PDO $pdo, string $db, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS '
            . 'WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute(['db' => $db, 'table' => $table]);

        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[$row['COLUMN_NAME']] = $row['COLUMN_TYPE'];
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function readPrimaryKey(PDO $pdo, string $db, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND CONSTRAINT_NAME = :pk '
            . 'ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute(['db' => $db, 'table' => $table, 'pk' => 'PRIMARY']);

        /** @var list<string> */
        return array_values($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<list<string>>
     */
    private function readUniqueKeys(PDO $pdo, string $db, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT CONSTRAINT_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND CONSTRAINT_NAME != :pk '
            . 'AND CONSTRAINT_NAME IN ('
            . '  SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS '
            . '  WHERE TABLE_SCHEMA = :db2 AND TABLE_NAME = :table2 AND CONSTRAINT_TYPE = :type'
            . ') ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION'
        );
        $stmt->execute([
            'db' => $db,
            'table' => $table,
            'pk' => 'PRIMARY',
            'db2' => $db,
            'table2' => $table,
            'type' => 'UNIQUE',
        ]);

        $keys = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $keys[$row['CONSTRAINT_NAME']][] = $row['COLUMN_NAME'];
        }

        return array_values(array_map('array_values', $keys));
    }

    private function currentDatabase(PDO $pdo): string
    {
        $stmt = $pdo->query('SELECT DATABASE()');

        return $stmt !== false ? (string) $stmt->fetchColumn() : '';
    }
}
