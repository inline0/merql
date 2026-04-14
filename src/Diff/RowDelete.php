<?php

declare(strict_types=1);

namespace Merql\Diff;

/**
 * A row that was deleted (exists in base but not in current).
 */
final readonly class RowDelete
{
    /**
     * @param string $table Table name.
     * @param string $rowKey Row identity key.
     * @param array<string, mixed> $oldValues Column values before deletion.
     */
    public function __construct(
        public string $table,
        public string $rowKey,
        public array $oldValues,
    ) {
    }
}
