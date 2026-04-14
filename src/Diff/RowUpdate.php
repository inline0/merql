<?php

declare(strict_types=1);

namespace Merql\Diff;

/**
 * A row that was updated (exists in both base and current, content differs).
 */
final readonly class RowUpdate
{
    /**
     * @param string $table Table name.
     * @param string $rowKey Row identity key.
     * @param list<ColumnDiff> $columnDiffs Per-column changes.
     * @param array<string, mixed> $fullRow Complete current row data.
     */
    public function __construct(
        public string $table,
        public string $rowKey,
        public array $columnDiffs,
        public array $fullRow,
    ) {
    }

    /**
     * @return array<string, ColumnDiff>
     */
    public function columnDiffMap(): array
    {
        $map = [];
        foreach ($this->columnDiffs as $diff) {
            $map[$diff->column] = $diff;
        }

        return $map;
    }
}
