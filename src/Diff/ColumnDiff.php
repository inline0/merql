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
        public string|int|float|bool|null $oldValue,
        public string|int|float|bool|null $newValue,
    ) {
    }
}
