<?php

declare(strict_types=1);

namespace Merql\Apply;

use Merql\Driver\Driver;
use Merql\Merge\MergeOperation;
use Merql\Merge\MergeResult;
use Merql\Snapshot\Snapshot;
use Merql\Snapshot\Snapshotter;

/**
 * Generates parameterized SQL statements from a merge result.
 */
final class SqlGenerator
{
    /**
     * Generate SQL statements for all operations.
     *
     * @param array<string, list<string>> $fkDependencies FK dependency map (child to parents).
     * @return list<array{sql: string, params: list<mixed>}>
     */
    public static function generate(
        MergeResult $result,
        ?Snapshot $base = null,
        array $fkDependencies = [],
        ?Driver $driver = null,
    ): array {
        $q = self::quoter($driver);
        $statements = [];

        $inserts = [];
        $updates = [];
        $deletes = [];

        foreach ($result->operations() as $op) {
            match ($op->type) {
                MergeOperation::TYPE_INSERT => $inserts[] = $op,
                MergeOperation::TYPE_UPDATE => $updates[] = $op,
                MergeOperation::TYPE_DELETE => $deletes[] = $op,
                default => null,
            };
        }

        if ($fkDependencies !== []) {
            $allTables = array_unique(array_map(fn($op) => $op->table, $result->operations()));
            $tableOrder = ForeignKeyResolver::topologicalSort($fkDependencies, array_values($allTables));

            $inserts = ForeignKeyResolver::sortOperations($tableOrder, $inserts);
            $updates = ForeignKeyResolver::sortOperations($tableOrder, $updates);

            $reverseOrder = array_reverse($tableOrder);
            $deletes = ForeignKeyResolver::sortOperations($reverseOrder, $deletes);
        }

        foreach ($inserts as $op) {
            $statements[] = self::generateInsert($op, $q);
        }

        foreach ($updates as $op) {
            $stmt = self::generateUpdate($op, $base, $q);
            if ($stmt !== null) {
                $statements[] = $stmt;
            }
        }

        foreach ($deletes as $op) {
            $statements[] = self::generateDelete($op, $base, $q);
        }

        return $statements;
    }

    /**
     * @param \Closure(string): string $q Identifier quoter.
     * @return array{sql: string, params: list<mixed>}
     */
    private static function generateInsert(MergeOperation $op, \Closure $q): array
    {
        $columns = array_keys($op->values);
        $quotedCols = array_map($q, $columns);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $q($op->table),
            implode(', ', $quotedCols),
            implode(', ', $placeholders),
        );

        return ['sql' => $sql, 'params' => array_values($op->values)];
    }

    /**
     * @param \Closure(string): string $q Identifier quoter.
     * @return array{sql: string, params: list<mixed>}|null
     */
    private static function generateUpdate(MergeOperation $op, ?Snapshot $base, \Closure $q): ?array
    {
        $identityColumns = self::getIdentityColumns($op, $base);

        $setClauses = [];
        $params = [];

        foreach ($op->values as $col => $val) {
            if (in_array($col, $identityColumns, true)) {
                continue;
            }
            $setClauses[] = $q($col) . ' = ?';
            $params[] = $val;
        }

        if ($setClauses === []) {
            return null;
        }

        $whereClauses = [];
        $keyParts = Snapshotter::decodeRowKey($op->rowKey);
        foreach ($identityColumns as $i => $col) {
            $whereClauses[] = $q($col) . ' = ?';
            $params[] = $keyParts[$i] ?? '';
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $q($op->table),
            implode(', ', $setClauses),
            implode(' AND ', $whereClauses),
        );

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @param \Closure(string): string $q Identifier quoter.
     * @return array{sql: string, params: list<mixed>}
     */
    private static function generateDelete(MergeOperation $op, ?Snapshot $base, \Closure $q): array
    {
        $identityColumns = self::getIdentityColumns($op, $base);

        $whereClauses = [];
        $params = [];
        $keyParts = Snapshotter::decodeRowKey($op->rowKey);

        foreach ($identityColumns as $i => $col) {
            $whereClauses[] = $q($col) . ' = ?';
            $params[] = $keyParts[$i] ?? '';
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $q($op->table),
            implode(' AND ', $whereClauses),
        );

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @return list<string>
     */
    private static function getIdentityColumns(MergeOperation $op, ?Snapshot $base): array
    {
        if ($base !== null) {
            $tableSnapshot = $base->getTable($op->table);
            if ($tableSnapshot !== null) {
                return $tableSnapshot->identityColumns;
            }
        }

        $columns = array_keys($op->values);

        return $columns !== [] ? [$columns[0]] : ['id'];
    }

    /**
     * Build a quoting closure. Defaults to backtick (MySQL) when no driver.
     *
     * @return \Closure(string): string
     */
    private static function quoter(?Driver $driver): \Closure
    {
        if ($driver !== null) {
            return fn(string $name) => $driver->quoteIdentifier($name);
        }

        // Default: backtick quoting (MySQL-compatible).
        return fn(string $name) => '`' . str_replace('`', '``', $name) . '`';
    }
}
