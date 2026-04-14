<?php

declare(strict_types=1);

namespace Merql\Schema;

/**
 * Table structure: columns, types, and primary key.
 */
final readonly class TableSchema
{
    /**
     * @param string $name Table name.
     * @param array<string, string> $columns Column name to type mapping.
     * @param list<string> $primaryKey Primary key columns (empty if none).
     * @param list<list<string>> $uniqueKeys Unique key column groups.
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $primaryKey = [],
        public array $uniqueKeys = [],
    ) {
    }

    public function hasPrimaryKey(): bool
    {
        return $this->primaryKey !== [];
    }

    public function hasUniqueKeys(): bool
    {
        return $this->uniqueKeys !== [];
    }

    /**
     * @return list<string>
     */
    public function columnNames(): array
    {
        return array_keys($this->columns);
    }
}
