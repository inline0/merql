<?php

declare(strict_types=1);

namespace Merql\Rollback;

/**
 * Serializable rollback plan.
 */
final readonly class RollbackPlan
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param list<string> $selectedOperationIds
     * @param list<string> $tables
     * @param list<RollbackOperation> $operations
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $mergePlanId,
        public array $selectedOperationIds,
        public array $tables,
        public array $operations,
        public int $createdAt,
        public string $hash,
        public array $metadata = [],
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'id' => $this->id,
            'merge_plan_id' => $this->mergePlanId,
            'selected_operation_ids' => $this->selectedOperationIds,
            'tables' => $this->tables,
            'operations' => array_map(
                static fn(RollbackOperation $operation): array => $operation->toArray(),
                $this->operations,
            ),
            'created_at' => $this->createdAt,
            'hash' => $this->hash,
            'metadata' => $this->metadata,
        ];
    }
}
