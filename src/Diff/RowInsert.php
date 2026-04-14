<?php

declare(strict_types=1);

namespace Merql\Diff;

/**
 * A row that was inserted (exists in current but not in base).
 */
final readonly class RowInsert
{
    /**
     * @param string $table Table name.
     * @param string $rowKey Row identity key.
     * @param array<string, mixed> $values All column values.
     */
    public function __construct(
        public string $table,
        public string $rowKey,
        public array $values,
    ) {
    }
}
