<?php

declare(strict_types=1);

namespace Merql\Schema;

use Merql\Exceptions\SchemaException;
use Merql\Snapshot\Snapshot;

/**
 * Detects schema differences between snapshots.
 */
final class SchemaValidator
{
    /**
     * Compare schemas across base, ours, and theirs snapshots.
     * Returns a list of mismatches. Does not throw.
     *
     * @return list<SchemaException>
     */
    public static function validate(Snapshot $base, Snapshot $ours, Snapshot $theirs): array
    {
        $mismatches = [];
        $allTables = array_unique(array_merge(
            $base->tableNames(),
            $ours->tableNames(),
            $theirs->tableNames(),
        ));

        foreach ($allTables as $table) {
            $baseTable = $base->getTable($table);
            $oursTable = $ours->getTable($table);
            $theirsTable = $theirs->getTable($table);

            $baseCols = $baseTable !== null ? $baseTable->schema->columnNames() : null;
            $oursCols = $oursTable !== null ? $oursTable->schema->columnNames() : null;
            $theirsCols = $theirsTable !== null ? $theirsTable->schema->columnNames() : null;

            // Compare ours schema against base.
            if ($baseCols !== null && $oursCols !== null) {
                $added = array_diff($oursCols, $baseCols);
                $removed = array_diff($baseCols, $oursCols);
                if ($added !== []) {
                    $mismatches[] = SchemaException::mismatch(
                        $table,
                        'columns added in ours: ' . implode(', ', $added),
                    );
                }
                if ($removed !== []) {
                    $mismatches[] = SchemaException::mismatch(
                        $table,
                        'columns removed in ours: ' . implode(', ', $removed),
                    );
                }
            }

            // Compare theirs schema against base.
            if ($baseCols !== null && $theirsCols !== null) {
                $added = array_diff($theirsCols, $baseCols);
                $removed = array_diff($baseCols, $theirsCols);
                if ($added !== []) {
                    $mismatches[] = SchemaException::mismatch(
                        $table,
                        'columns added in theirs: ' . implode(', ', $added),
                    );
                }
                if ($removed !== []) {
                    $mismatches[] = SchemaException::mismatch(
                        $table,
                        'columns removed in theirs: ' . implode(', ', $removed),
                    );
                }
            }
        }

        return $mismatches;
    }
}
