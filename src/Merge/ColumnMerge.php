<?php

declare(strict_types=1);

namespace Merql\Merge;

use Merql\CellMerge\CellMergeConfig;
use Merql\Diff\Differ;

/**
 * Per-column merge logic for a single row.
 */
final class ColumnMerge
{
    /**
     * Merge two versions of a row at the column level.
     *
     * @param array<string, mixed> $base Base row data.
     * @param array<string, mixed> $ours Our version of the row.
     * @param array<string, mixed> $theirs Their version of the row.
     * @param array<string, string> $columnTypes Column name to type mapping (for cell merger lookup).
     * @return array{values: array<string, mixed>, conflicts: list<Conflict>}
     */
    public static function merge(
        string $table,
        string $rowKey,
        array $base,
        array $ours,
        array $theirs,
        ?CellMergeConfig $cellMergeConfig = null,
        array $columnTypes = [],
    ): array {
        $allColumns = array_unique(array_merge(
            array_keys($base),
            array_keys($ours),
            array_keys($theirs),
        ));

        $merged = [];
        $conflicts = [];

        foreach ($allColumns as $column) {
            $baseVal = $base[$column] ?? null;
            $oursVal = $ours[$column] ?? null;
            $theirsVal = $theirs[$column] ?? null;

            $oursChanged = !Differ::valuesEqual($baseVal, $oursVal);
            $theirsChanged = !Differ::valuesEqual($baseVal, $theirsVal);

            if (!$oursChanged && !$theirsChanged) {
                $merged[$column] = $baseVal;
            } elseif (!$oursChanged) {
                $merged[$column] = $theirsVal;
            } elseif (!$theirsChanged) {
                $merged[$column] = $oursVal;
            } elseif (Differ::valuesEqual($oursVal, $theirsVal)) {
                $merged[$column] = $oursVal;
            } else {
                // Both changed to different values. Try cell-level merge.
                if ($cellMergeConfig !== null) {
                    $cellMerger = $cellMergeConfig->getMerger($table, $column, $columnTypes[$column] ?? '');
                    $cellResult = $cellMerger->merge($baseVal, $oursVal, $theirsVal);

                    if ($cellResult->clean) {
                        $merged[$column] = $cellResult->value;
                        continue;
                    }

                    // Cell merger couldn't fully resolve. Use its best-effort value.
                    $merged[$column] = $cellResult->value;
                } else {
                    $merged[$column] = $oursVal;
                }

                $conflicts[] = new Conflict(
                    $table,
                    $rowKey,
                    'update_update',
                    $column,
                    $oursVal,
                    $theirsVal,
                    $baseVal,
                );
            }
        }

        return ['values' => $merged, 'conflicts' => $conflicts];
    }
}
