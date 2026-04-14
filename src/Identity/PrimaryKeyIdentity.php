<?php

declare(strict_types=1);

namespace Merql\Identity;

/**
 * Match rows by primary key columns.
 */
final readonly class PrimaryKeyIdentity implements RowIdentity
{
    /**
     * @param list<string> $pkColumns Primary key column names.
     */
    public function __construct(
        private array $pkColumns,
    ) {
    }

    public function key(array $row): string
    {
        return \Merql\Snapshot\Snapshotter::buildRowKey($row, $this->pkColumns);
    }

    public function columns(): array
    {
        return $this->pkColumns;
    }
}
