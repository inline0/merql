<?php

declare(strict_types=1);

namespace Merql\Rollback;

use Merql\Merge\MergeOperation;
use Merql\Plan\MergePlan;
use Merql\Plan\OperationSelection;
use Merql\Snapshot\Snapshotter;

/**
 * Builds inverse operations from selected merge plan operations.
 */
final class RollbackPlanBuilder
{
    /**
     * @param array<string, array<string, scalar|null>|null> $liveBeforeRows Operation ID to live before row.
     * @param array<string, mixed> $metadata
     */
    public function build(
        string $id,
        MergePlan $plan,
        OperationSelection $selection,
        array $liveBeforeRows,
        array $metadata = [],
        ?int $createdAt = null,
    ): RollbackPlan {
        $operations = [];
        $tables = [];

        foreach ($plan->operations as $operation) {
            if (!$selection->contains($operation->id)) {
                continue;
            }

            $beforeRow = $liveBeforeRows[$operation->id] ?? null;
            $operations[] = new RollbackOperation(
                $operation->id,
                $this->inverseOperation($operation, $beforeRow),
                $beforeRow,
                $this->expectedAfterRow($operation),
            );
            $tables[$operation->table] = true;
        }

        $tableNames = array_keys($tables);
        sort($tableNames);
        $selectedOperationIds = $selection->operationIds;
        $createdAt ??= time();
        $hash = self::hashPayload([
            'merge_plan_id' => $plan->id,
            'selected_operation_ids' => $selectedOperationIds,
            'operations' => array_map(
                static fn(RollbackOperation $operation): array => $operation->toArray(),
                $operations,
            ),
        ]);

        return new RollbackPlan(
            $id,
            $plan->id,
            $selectedOperationIds,
            $tableNames,
            $operations,
            $createdAt,
            $hash,
            $metadata,
        );
    }

    /**
     * @param array<string, scalar|null>|null $beforeRow
     */
    private function inverseOperation(
        \Merql\Plan\MergePlanOperation $operation,
        ?array $beforeRow,
    ): MergeOperation {
        if ($operation->type === MergeOperation::TYPE_INSERT) {
            return new MergeOperation(
                MergeOperation::TYPE_DELETE,
                $operation->table,
                $operation->rowKey,
                $operation->resultValues,
                'rollback',
            );
        }

        if ($operation->type === MergeOperation::TYPE_DELETE) {
            return new MergeOperation(
                MergeOperation::TYPE_INSERT,
                $operation->table,
                $operation->rowKey,
                $beforeRow ?? $operation->baseRow ?? [],
                'rollback',
            );
        }

        return new MergeOperation(
            MergeOperation::TYPE_UPDATE,
            $operation->table,
            $operation->rowKey,
            $beforeRow ?? $operation->baseRow ?? [],
            'rollback',
        );
    }

    /**
     * @return array<string, scalar|null>|null
     */
    private function expectedAfterRow(\Merql\Plan\MergePlanOperation $operation): ?array
    {
        if ($operation->type === MergeOperation::TYPE_DELETE) {
            return null;
        }

        if ($operation->type === MergeOperation::TYPE_INSERT) {
            return $operation->resultValues;
        }

        $row = $operation->theirsRow ?? [];
        foreach ($operation->resultValues as $column => $value) {
            $row[$column] = $value;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function hashPayload(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $encoded);
    }
}
