<?php

declare(strict_types=1);

namespace Merql\Merge;

/**
 * Result of a three-way merge: clean operations and conflicts.
 */
final readonly class MergeResult
{
    /**
     * @param list<MergeOperation> $operations Resolved operations.
     * @param list<Conflict> $conflicts Unresolved conflicts.
     */
    public function __construct(
        private array $operations = [],
        private array $conflicts = [],
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

    public function operationCount(): int
    {
        return count($this->operations);
    }

    public function conflictCount(): int
    {
        return count($this->conflicts);
    }
}
