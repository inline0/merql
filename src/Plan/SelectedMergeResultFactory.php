<?php

declare(strict_types=1);

namespace Merql\Plan;

use Merql\Merge\MergeOperation;
use Merql\Merge\MergeResult;
use Merql\Snapshot\Snapshot;

/**
 * Builds a MergeResult from selected plan operations.
 */
final class SelectedMergeResultFactory
{
    public function fromOperationSelection(
        MergePlan $plan,
        OperationSelection $selection,
        ?Snapshot $baseSnapshot = null,
    ): MergeResult {
        $operations = [];
        foreach ($plan->operations as $operation) {
            if (!$selection->contains($operation->id)) {
                continue;
            }

            $operations[] = new MergeOperation(
                $operation->type,
                $operation->table,
                $operation->rowKey,
                $operation->resultValues,
                $operation->source,
            );
        }

        return new MergeResult($operations, [], $baseSnapshot);
    }

    public function fromChangeGroupSelection(
        MergePlan $plan,
        ChangeGroupSelection $selection,
        ?Snapshot $baseSnapshot = null,
    ): MergeResult {
        return $this->fromOperationSelection(
            $plan,
            $selection->toOperationSelection($plan),
            $baseSnapshot,
        );
    }
}
