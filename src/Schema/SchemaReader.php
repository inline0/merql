<?php

declare(strict_types=1);

namespace Merql\Schema;

use PDO;

/**
 * Reads table schemas from MySQL via INFORMATION_SCHEMA.
 */
final class SchemaReader
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function read(string $table): TableSchema
    {
        $columns = $this->readColumns($table);
        $primaryKey = $this->readPrimaryKey($table);
        $uniqueKeys = $this->readUniqueKeys($table);

        return new TableSchema($table, $columns, $primaryKey, $uniqueKeys);
    }

    /**
     * @return list<string>
     */
    public function listTables(): array
    {
        $dbName = $this->currentDatabase();
        $stmt = $this->pdo->prepare(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES '
            . 'WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = :type ORDER BY TABLE_NAME'
        );
        $stmt->execute(['db' => $dbName, 'type' => 'BASE TABLE']);

        /** @var list<string> */
        return array_values($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array<string, string>
     */
    private function readColumns(string $table): array
    {
        $dbName = $this->currentDatabase();
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS '
            . 'WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute(['db' => $dbName, 'table' => $table]);

        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[$row['COLUMN_NAME']] = $row['COLUMN_TYPE'];
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function readPrimaryKey(string $table): array
    {
        $dbName = $this->currentDatabase();
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND CONSTRAINT_NAME = :pk '
            . 'ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute(['db' => $dbName, 'table' => $table, 'pk' => 'PRIMARY']);

        /** @var list<string> */
        return array_values($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<list<string>>
     */
    private function readUniqueKeys(string $table): array
    {
        $dbName = $this->currentDatabase();
        $stmt = $this->pdo->prepare(
            'SELECT CONSTRAINT_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND CONSTRAINT_NAME != :pk '
            . 'AND CONSTRAINT_NAME IN ('
            . '  SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS '
            . '  WHERE TABLE_SCHEMA = :db2 AND TABLE_NAME = :table2 AND CONSTRAINT_TYPE = :type'
            . ') ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION'
        );
        $stmt->execute([
            'db' => $dbName,
            'table' => $table,
            'pk' => 'PRIMARY',
            'db2' => $dbName,
            'table2' => $table,
            'type' => 'UNIQUE',
        ]);

        $keys = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $keys[$row['CONSTRAINT_NAME']][] = $row['COLUMN_NAME'];
        }

        return array_values(array_map('array_values', $keys));
    }

    private function currentDatabase(): string
    {
        $stmt = $this->pdo->query('SELECT DATABASE()');

        return $stmt !== false ? (string) $stmt->fetchColumn() : '';
    }
}
