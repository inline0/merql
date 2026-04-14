<?php

declare(strict_types=1);

namespace Merql\Diff;

/**
 * Collection of operations representing differences between two snapshots.
 */
final readonly class Changeset
{
    /**
     * @param list<RowInsert> $inserts
     * @param list<RowUpdate> $updates
     * @param list<RowDelete> $deletes
     */
    public function __construct(
        private array $inserts = [],
        private array $updates = [],
        private array $deletes = [],
    ) {
    }

    /**
     * @return list<RowInsert>
     */
    public function inserts(): array
    {
        return $this->inserts;
    }

    /**
     * @return list<RowUpdate>
     */
    public function updates(): array
    {
        return $this->updates;
    }

    /**
     * @return list<RowDelete>
     */
    public function deletes(): array
    {
        return $this->deletes;
    }

    public function isEmpty(): bool
    {
        return $this->inserts === [] && $this->updates === [] && $this->deletes === [];
    }

    public function count(): int
    {
        return count($this->inserts) + count($this->updates) + count($this->deletes);
    }

    /**
     * Get operations for a single table.
     */
    public function forTable(string $table): self
    {
        return new self(
            array_values(array_filter($this->inserts, fn(RowInsert $i) => $i->table === $table)),
            array_values(array_filter($this->updates, fn(RowUpdate $u) => $u->table === $table)),
            array_values(array_filter($this->deletes, fn(RowDelete $d) => $d->table === $table)),
        );
    }
}
