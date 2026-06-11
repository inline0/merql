<?php

declare(strict_types=1);

namespace Merql\Driver;

use Merql\Database\DatabaseConnection;
use Merql\Schema\TableSchema;

/**
 * MySQL/MariaDB driver implementation.
 */
final class MysqlDriver implements Driver
{
    public function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function listTables(DatabaseConnection $connection): array
    {
        $db = $this->currentDatabase($connection);
        $rows = $connection->query(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES '
            . 'WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME',
            [$db, 'BASE TABLE'],
        );

        $tables = [];
        foreach ($rows as $row) {
            $table = $row['TABLE_NAME'] ?? null;
            if (is_string($table)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    public function readSchema(DatabaseConnection $connection, string $table): TableSchema
    {
        $db = $this->currentDatabase($connection);

        $columns = $this->readColumns($connection, $db, $table);
        $primaryKey = $this->readPrimaryKey($connection, $db, $table);
        $uniqueKeys = $this->readUniqueKeys($connection, $db, $table);

        return new TableSchema($table, $columns, $primaryKey, $uniqueKeys);
    }

    public function readForeignKeys(DatabaseConnection $connection): array
    {
        $db = $this->currentDatabase($connection);
        $rows = $connection->query(
            'SELECT TABLE_NAME, REFERENCED_TABLE_NAME '
            . 'FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = ? '
            . 'AND REFERENCED_TABLE_NAME IS NOT NULL '
            . 'GROUP BY TABLE_NAME, REFERENCED_TABLE_NAME',
            [$db],
        );

        $deps = [];
        foreach ($rows as $row) {
            $tableName = $row['TABLE_NAME'] ?? null;
            $referenced = $row['REFERENCED_TABLE_NAME'] ?? null;
            if (!is_string($tableName) || !is_string($referenced)) {
                continue;
            }
            $deps[$tableName][] = $referenced;
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
    private function readColumns(DatabaseConnection $connection, string $db, string $table): array
    {
        $rows = $connection->query(
            'SELECT COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS '
            . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$db, $table],
        );

        $columns = [];
        foreach ($rows as $row) {
            $name = $row['COLUMN_NAME'] ?? null;
            $type = $row['COLUMN_TYPE'] ?? null;
            if (!is_string($name) || !is_string($type)) {
                continue;
            }
            $columns[$name] = $type;
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function readPrimaryKey(DatabaseConnection $connection, string $db, string $table): array
    {
        $rows = $connection->query(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? '
            . 'ORDER BY ORDINAL_POSITION',
            [$db, $table, 'PRIMARY'],
        );

        $columns = [];
        foreach ($rows as $row) {
            $column = $row['COLUMN_NAME'] ?? null;
            if (is_string($column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @return list<list<string>>
     */
    private function readUniqueKeys(DatabaseConnection $connection, string $db, string $table): array
    {
        $rows = $connection->query(
            'SELECT CONSTRAINT_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME != ? '
            . 'AND CONSTRAINT_NAME IN ('
            . '  SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS '
            . '  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = ?'
            . ') ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION',
            [$db, $table, 'PRIMARY', $db, $table, 'UNIQUE'],
        );

        $keys = [];
        foreach ($rows as $row) {
            $constraint = $row['CONSTRAINT_NAME'] ?? null;
            $column = $row['COLUMN_NAME'] ?? null;
            if (!is_string($constraint) || !is_string($column)) {
                continue;
            }
            $keys[$constraint][] = $column;
        }

        return array_values(array_map('array_values', $keys));
    }

    private function currentDatabase(DatabaseConnection $connection): string
    {
        return (string) $connection->scalar('SELECT DATABASE()');
    }
}
