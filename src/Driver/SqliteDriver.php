<?php

declare(strict_types=1);

namespace Merql\Driver;

use Merql\Database\DatabaseConnection;
use Merql\Schema\TableSchema;

/**
 * SQLite driver implementation.
 */
final class SqliteDriver implements Driver
{
    public function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function listTables(DatabaseConnection $connection): array
    {
        $rows = $connection->query(
            "SELECT name FROM sqlite_master "
            . "WHERE type = 'table' AND name NOT LIKE 'sqlite_%' "
            . "ORDER BY name",
        );

        $tables = [];
        foreach ($rows as $row) {
            $table = $row['name'] ?? null;
            if (is_string($table)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    public function readSchema(DatabaseConnection $connection, string $table): TableSchema
    {
        $columns = $this->readColumns($connection, $table);
        $primaryKey = $this->readPrimaryKey($connection, $table);
        $uniqueKeys = $this->readUniqueKeys($connection, $table);

        return new TableSchema($table, $columns, $primaryKey, $uniqueKeys);
    }

    public function readForeignKeys(DatabaseConnection $connection): array
    {
        $tables = $this->listTables($connection);
        $deps = [];

        foreach ($tables as $table) {
            $fkRows = $connection->query("PRAGMA foreign_key_list(" . $this->quoteIdentifier($table) . ")");

            $parents = [];
            foreach ($fkRows as $row) {
                $parent = $row['table'] ?? null;
                if (!is_string($parent)) {
                    continue;
                }
                $parents[] = $parent;
            }

            $parents = array_values(array_unique($parents));
            if ($parents !== []) {
                $deps[$table] = $parents;
            }
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
    private function readColumns(DatabaseConnection $connection, string $table): array
    {
        $rows = $connection->query("PRAGMA table_info(" . $this->quoteIdentifier($table) . ")");

        $columns = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            $type = $row['type'] ?? null;
            if (!is_string($name)) {
                continue;
            }
            $columns[$name] = strtolower(is_string($type) && $type !== '' ? $type : 'text');
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function readPrimaryKey(DatabaseConnection $connection, string $table): array
    {
        $rows = $connection->query("PRAGMA table_info(" . $this->quoteIdentifier($table) . ")");

        $pkColumns = [];
        foreach ($rows as $row) {
            $pk = $row['pk'] ?? null;
            $name = $row['name'] ?? null;
            if (!is_numeric($pk) || !is_string($name)) {
                continue;
            }
            $pkOrder = (int) $pk;
            if ($pkOrder > 0) {
                $pkColumns[$pkOrder] = $name;
            }
        }

        ksort($pkColumns);

        return array_values($pkColumns);
    }

    /**
     * @return list<list<string>>
     */
    private function readUniqueKeys(DatabaseConnection $connection, string $table): array
    {
        $indexes = $connection->query("PRAGMA index_list(" . $this->quoteIdentifier($table) . ")");

        $uniqueKeys = [];
        foreach ($indexes as $index) {
            $unique = $index['unique'] ?? null;
            if (!is_numeric($unique) || (int) $unique !== 1) {
                continue;
            }

            if (($index['origin'] ?? null) === 'pk') {
                continue;
            }

            $indexName = $index['name'] ?? null;
            if (!is_string($indexName)) {
                continue;
            }

            $indexColumns = $connection->query("PRAGMA index_info(" . $this->quoteIdentifier($indexName) . ")");

            $cols = [];
            foreach ($indexColumns as $col) {
                $colName = $col['name'] ?? null;
                if (!is_string($colName)) {
                    continue;
                }
                $cols[] = $colName;
            }

            if ($cols !== []) {
                $uniqueKeys[] = $cols;
            }
        }

        return $uniqueKeys;
    }
}
