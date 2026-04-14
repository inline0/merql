<?php

declare(strict_types=1);

namespace Merql\CellMerge;

/**
 * Default cell merger: treats values as opaque strings.
 * Always returns a conflict when values differ.
 */
final class OpaqueCellMerger implements CellMerger
{
    public function merge(mixed $base, mixed $ours, mixed $theirs): CellMergeResult
    {
        return CellMergeResult::conflict($ours);
    }
}
