<?php

declare(strict_types=1);

namespace Merql\CellMerge;

/**
 * Default cell merger: treats values as opaque strings.
 * Always returns a conflict when values differ.
 */
final class OpaqueCellMerger implements CellMerger
{
    public function merge(
        string|int|float|bool|null $base,
        string|int|float|bool|null $ours,
        string|int|float|bool|null $theirs,
    ): CellMergeResult {
        return CellMergeResult::conflict($ours);
    }
}
