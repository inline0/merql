<?php

declare(strict_types=1);

namespace Merql\Identity;

/**
 * Determines how to identify "the same row" across snapshots.
 */
interface RowIdentity
{
    /**
     * Build a unique key for a row.
     *
     * @param array<string, mixed> $row Column values.
     * @return string Unique key identifying this row.
     */
    public function key(array $row): string;

    /**
     * @return list<string> Columns used for identity.
     */
    public function columns(): array;
}
