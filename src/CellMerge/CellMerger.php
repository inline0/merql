<?php

declare(strict_types=1);

namespace Merql\CellMerge;

/**
 * Strategy for merging content within a single cell.
 *
 * When both sides change the same column to different values,
 * ColumnMerge delegates to a CellMerger to attempt a deeper merge
 * before declaring a conflict.
 */
interface CellMerger
{
    /**
     * Attempt to merge three versions of a cell value.
     *
     * Return CellMergeResult::resolved() if the merge is clean,
     * or CellMergeResult::conflict() if it cannot be resolved.
     */
    public function merge(mixed $base, mixed $ours, mixed $theirs): CellMergeResult;
}
