<?php

declare(strict_types=1);

namespace Merql\Identity;

/**
 * Match rows by unique columns (for tables without auto-increment PK).
 */
final readonly class NaturalKeyIdentity implements RowIdentity
{
    /**
     * @param list<string> $uniqueColumns Unique constraint column names.
     */
    public function __construct(
        private array $uniqueColumns,
    ) {
    }

    public function key(array $row): string
    {
        $parts = [];
        foreach ($this->uniqueColumns as $col) {
            $parts[] = (string) ($row[$col] ?? '');
        }

        return implode("\x1F", $parts);
    }

    public function columns(): array
    {
        return $this->uniqueColumns;
    }
}
