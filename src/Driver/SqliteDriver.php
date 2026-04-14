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
                $parents[] = $row['table'];
            }

            $parents = array_unique($parents);
            if ($parents !== []) {
                $deps[$table] = array_values($parents);
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
            $columns[$row['name']] = strtolower($row['type'] ?: 'text');
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
            if ((int) $row['pk'] > 0) {
                $pkColumns[(int) $row['pk']] = $row['name'];
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
            if ((int) $index['unique'] !== 1) {
                continue;
            }

            // Skip auto-index (PK-backing index).
            if (str_starts_with($index['name'], 'sqlite_autoindex_')) {
                continue;
            }

            $colStmt = $pdo->query("PRAGMA index_info(" . $this->quoteIdentifier($index['name']) . ")");
            if ($colStmt === false) {
                continue;
            }

            $cols = [];
            foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $cols[] = $col['name'];
            }

            if ($cols !== []) {
                $uniqueKeys[] = $cols;
            }
        }

        return $uniqueKeys;
    }
}
