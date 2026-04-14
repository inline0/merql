<?php

declare(strict_types=1);

namespace Merql\Merge;

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
     * @return array{values: array<string, mixed>, conflicts: list<Conflict>}
     */
    public static function merge(
        string $table,
        string $rowKey,
        array $base,
        array $ours,
        array $theirs,
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
                // Neither changed: keep base.
                $merged[$column] = $baseVal;
            } elseif (!$oursChanged) {
                // Only theirs changed: accept theirs.
                $merged[$column] = $theirsVal;
            } elseif (!$theirsChanged) {
                // Only ours changed: accept ours.
                $merged[$column] = $oursVal;
            } elseif (Differ::valuesEqual($oursVal, $theirsVal)) {
                // Both changed to the same value: accept (agree).
                $merged[$column] = $oursVal;
            } else {
                // Both changed to different values: conflict.
                $merged[$column] = $oursVal; // Default to ours for the merged value.
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
