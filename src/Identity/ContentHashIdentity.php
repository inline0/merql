<?php

declare(strict_types=1);

namespace Merql\Identity;

use Merql\Snapshot\RowFingerprint;

/**
 * Match rows by content hash (for tables without primary or unique keys).
 */
final readonly class ContentHashIdentity implements RowIdentity
{
    /**
     * @param list<string> $allColumns All column names in the table.
     */
    public function __construct(
        private array $allColumns,
    ) {
    }

    public function key(array $row): string
    {
        return RowFingerprint::compute($row);
    }

    public function columns(): array
    {
        return $this->allColumns;
    }
}
