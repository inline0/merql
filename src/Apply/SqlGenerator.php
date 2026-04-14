<?php

declare(strict_types=1);

namespace Merql\Apply;

use Merql\Merge\MergeOperation;
use Merql\Merge\MergeResult;
use Merql\Snapshot\Snapshot;

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
    ): array {
        $statements = [];

        // Separate by operation type.
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

        // Sort by FK dependency order if available.
        if ($fkDependencies !== []) {
            $allTables = array_unique(array_map(fn($op) => $op->table, $result->operations()));
            $tableOrder = ForeignKeyResolver::topologicalSort($fkDependencies, array_values($allTables));

            // Inserts/updates: parent tables first.
            $inserts = ForeignKeyResolver::sortOperations($tableOrder, $inserts);
            $updates = ForeignKeyResolver::sortOperations($tableOrder, $updates);

            // Deletes: child tables first (reverse order).
            $reverseOrder = array_reverse($tableOrder);
            $deletes = ForeignKeyResolver::sortOperations($reverseOrder, $deletes);
        }

        // Order: inserts first, then updates, then deletes.
        foreach ($inserts as $op) {
            $statements[] = self::generateInsert($op);
        }

        foreach ($updates as $op) {
            $statements[] = self::generateUpdate($op, $base);
        }

        foreach ($deletes as $op) {
            $statements[] = self::generateDelete($op, $base);
        }

        return $statements;
    }

    /**
     * @return array{sql: string, params: list<mixed>}
     */
    private static function generateInsert(MergeOperation $op): array
    {
        $columns = array_keys($op->values);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $op->table,
            implode('`, `', $columns),
            implode(', ', $placeholders),
        );

        return ['sql' => $sql, 'params' => array_values($op->values)];
    }

    /**
     * @return array{sql: string, params: list<mixed>}
     */
    private static function generateUpdate(MergeOperation $op, ?Snapshot $base): array
    {
        $identityColumns = self::getIdentityColumns($op, $base);

        $setClauses = [];
        $params = [];

        foreach ($op->values as $col => $val) {
            if (in_array($col, $identityColumns, true)) {
                continue;
            }
            $setClauses[] = "`{$col}` = ?";
            $params[] = $val;
        }

        $whereClauses = [];
        $keyParts = explode("\x1F", $op->rowKey);
        foreach ($identityColumns as $i => $col) {
            $whereClauses[] = "`{$col}` = ?";
            $params[] = $keyParts[$i] ?? '';
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $op->table,
            implode(', ', $setClauses),
            implode(' AND ', $whereClauses),
        );

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @return array{sql: string, params: list<mixed>}
     */
    private static function generateDelete(MergeOperation $op, ?Snapshot $base): array
    {
        $identityColumns = self::getIdentityColumns($op, $base);

        $whereClauses = [];
        $params = [];
        $keyParts = explode("\x1F", $op->rowKey);

        foreach ($identityColumns as $i => $col) {
            $whereClauses[] = "`{$col}` = ?";
            $params[] = $keyParts[$i] ?? '';
        }

        $sql = sprintf(
            'DELETE FROM `%s` WHERE %s',
            $op->table,
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

        // Fallback: parse from row key using value columns.
        // If we have values, use the first column as PK heuristic.
        $columns = array_keys($op->values);

        return $columns !== [] ? [$columns[0]] : ['id'];
    }
}
