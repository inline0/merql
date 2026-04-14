<?php

declare(strict_types=1);

namespace Merql\Filter;

/**
 * Ignore specific columns during snapshot and merge.
 */
final readonly class ColumnFilter
{
    /**
     * @param list<string> $ignoreColumns Column names to ignore.
     */
    private function __construct(
        private array $ignoreColumns,
    ) {
    }

    /**
     * @param list<string> $columns Column names to ignore.
     */
    public static function ignore(array $columns): self
    {
        return new self($columns);
    }

    /**
     * Remove ignored columns from a row.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function applyToRow(array $row): array
    {
        foreach ($this->ignoreColumns as $col) {
            unset($row[$col]);
        }

        return $row;
    }
}
