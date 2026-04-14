<?php

declare(strict_types=1);

namespace Merql\Diff;

use Merql\Snapshot\Snapshot;
use Merql\Snapshot\TableSnapshot;

/**
 * Compares two snapshots and produces a changeset of differences.
 */
final class Differ
{
    public function diff(Snapshot $base, Snapshot $current): Changeset
    {
        $inserts = [];
        $updates = [];
        $deletes = [];

        $allTables = array_unique(array_merge($base->tableNames(), $current->tableNames()));

        foreach ($allTables as $table) {
            $baseTable = $base->getTable($table);
            $currentTable = $current->getTable($table);

            if ($baseTable === null && $currentTable !== null) {
                // Entire table is new: all rows are inserts.
                foreach ($currentTable->rowKeys() as $key) {
                    $inserts[] = new RowInsert($table, $key, $currentTable->getRow($key) ?? []);
                }
                continue;
            }

            if ($baseTable !== null && $currentTable === null) {
                // Entire table removed: all rows are deletes.
                foreach ($baseTable->rowKeys() as $key) {
                    $deletes[] = new RowDelete($table, $key, $baseTable->getRow($key) ?? []);
                }
                continue;
            }

            if ($baseTable !== null && $currentTable !== null) {
                $this->diffTable($table, $baseTable, $currentTable, $inserts, $updates, $deletes);
            }
        }

        return new Changeset($inserts, $updates, $deletes);
    }

    /**
     * @param list<RowInsert> $inserts
     * @param list<RowUpdate> $updates
     * @param list<RowDelete> $deletes
     */
    private function diffTable(
        string $table,
        TableSnapshot $base,
        TableSnapshot $current,
        array &$inserts,
        array &$updates,
        array &$deletes,
    ): void {
        // Check existing rows for updates and deletes.
        foreach ($base->rowKeys() as $key) {
            if (!$current->hasRow($key)) {
                $deletes[] = new RowDelete($table, $key, $base->getRow($key) ?? []);
                continue;
            }

            // Fast path: fingerprint matches means no change.
            if ($base->getFingerprint($key) === $current->getFingerprint($key)) {
                continue;
            }

            // Fingerprint differs: compute per-column diff.
            $baseRow = $base->getRow($key) ?? [];
            $currentRow = $current->getRow($key) ?? [];
            $columnDiffs = $this->diffColumns($baseRow, $currentRow);

            if ($columnDiffs !== []) {
                $updates[] = new RowUpdate($table, $key, $columnDiffs, $currentRow);
            }
        }

        // Check for new rows (inserts).
        foreach ($current->rowKeys() as $key) {
            if (!$base->hasRow($key)) {
                $inserts[] = new RowInsert($table, $key, $current->getRow($key) ?? []);
            }
        }
    }

    /**
     * @param array<string, mixed> $baseRow
     * @param array<string, mixed> $currentRow
     * @return list<ColumnDiff>
     */
    private function diffColumns(array $baseRow, array $currentRow): array
    {
        $diffs = [];
        $allColumns = array_unique(array_merge(array_keys($baseRow), array_keys($currentRow)));

        foreach ($allColumns as $column) {
            $baseValue = $baseRow[$column] ?? null;
            $currentValue = $currentRow[$column] ?? null;

            if (!self::valuesEqual($baseValue, $currentValue)) {
                $diffs[] = new ColumnDiff($column, $baseValue, $currentValue);
            }
        }

        return $diffs;
    }

    /**
     * Compare two values, treating NULL as a distinct value.
     */
    public static function valuesEqual(mixed $a, mixed $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }

        if ($a === null || $b === null) {
            return false;
        }

        return (string) $a === (string) $b;
    }
}
