<?php

declare(strict_types=1);

namespace Merql\Driver;

use Merql\Schema\TableSchema;
use PDO;

/**
 * SQLite driver implementation.
 */
final class SqliteDriver implements Driver
{
    public function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function listTables(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT name FROM sqlite_master "
            . "WHERE type = 'table' AND name NOT LIKE 'sqlite_%' "
            . "ORDER BY name"
        );

        /** @var list<string> */
        return $stmt !== false ? array_values($stmt->fetchAll(PDO::FETCH_COLUMN)) : [];
    }

    public function readSchema(PDO $pdo, string $table): TableSchema
    {
        $columns = $this->readColumns($pdo, $table);
        $primaryKey = $this->readPrimaryKey($pdo, $table);
        $uniqueKeys = $this->readUniqueKeys($pdo, $table);

        return new TableSchema($table, $columns, $primaryKey, $uniqueKeys);
    }

    public function readForeignKeys(PDO $pdo): array
    {
        $tables = $this->listTables($pdo);
        $deps = [];

        foreach ($tables as $table) {
            $stmt = $pdo->query("PRAGMA foreign_key_list(" . $this->quoteIdentifier($table) . ")");
            if ($stmt === false) {
                continue;
            }

            $parents = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!is_array($row)) {
                    continue;
                }
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
    private function readColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query("PRAGMA table_info(" . $this->quoteIdentifier($table) . ")");
        if ($stmt === false) {
            return [];
        }

        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
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
    private function readPrimaryKey(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query("PRAGMA table_info(" . $this->quoteIdentifier($table) . ")");
        if ($stmt === false) {
            return [];
        }

        $pkColumns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
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
    private function readUniqueKeys(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query("PRAGMA index_list(" . $this->quoteIdentifier($table) . ")");
        if ($stmt === false) {
            return [];
        }

        $uniqueKeys = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if (!is_array($index)) {
                continue;
            }
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

            $colStmt = $pdo->query("PRAGMA index_info(" . $this->quoteIdentifier($indexName) . ")");
            if ($colStmt === false) {
                continue;
            }

            $cols = [];
            foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                if (!is_array($col)) {
                    continue;
                }
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
