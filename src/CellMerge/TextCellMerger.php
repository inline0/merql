<?php

declare(strict_types=1);

namespace Merql\CellMerge;

use Pitmaster\Merge\ThreeWayMerge;

/**
 * Merges multi-line text values using pitmaster's three-way merge.
 * Same algorithm git uses for file content: Myers diff, line-by-line.
 */
final class TextCellMerger implements CellMerger
{
    public function merge(mixed $base, mixed $ours, mixed $theirs): CellMergeResult
    {
        $result = ThreeWayMerge::merge(
            (string) ($base ?? ''),
            (string) ($ours ?? ''),
            (string) ($theirs ?? ''),
            'ours',
            'theirs',
        );

        if ($result['clean']) {
            return CellMergeResult::resolved($result['content']);
        }

        return CellMergeResult::conflict($result['content'], $result['conflicts']);
    }
}
