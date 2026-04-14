<?php

declare(strict_types=1);

namespace Merql\Merge;

use Merql\Exceptions\SchemaException;
use Merql\Snapshot\Snapshot;

/**
 * Result of a three-way merge: clean operations and conflicts.
 */
final readonly class MergeResult
{
    /**
     * @param list<MergeOperation> $operations Resolved operations.
     * @param list<Conflict> $conflicts Unresolved conflicts.
     * @param list<SchemaException> $schemaMismatches Schema mismatches detected during merge.
     */
    public function __construct(
        private array $operations = [],
        private array $conflicts = [],
        private ?Snapshot $baseSnapshot = null,
        private array $schemaMismatches = [],
    ) {
    }

    public function isClean(): bool
    {
        return $this->conflicts === [];
    }

    /**
     * @return list<MergeOperation>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * @return list<Conflict>
     */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    public function baseSnapshot(): ?Snapshot
    {
        return $this->baseSnapshot;
    }

    /**
     * @return list<SchemaException>
     */
    public function schemaMismatches(): array
    {
        return $this->schemaMismatches;
    }

    public function hasSchemaMismatches(): bool
    {
        return $this->schemaMismatches !== [];
    }

    public function operationCount(): int
    {
        return count($this->operations);
    }

    public function conflictCount(): int
    {
        return count($this->conflicts);
    }
}
