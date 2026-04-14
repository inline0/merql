<?php

declare(strict_types=1);

namespace Merql\Diff;

/**
 * A single column change: old value to new value.
 */
final readonly class ColumnDiff
{
    public function __construct(
        public string $column,
        public mixed $oldValue,
        public mixed $newValue,
    ) {
    }
}
