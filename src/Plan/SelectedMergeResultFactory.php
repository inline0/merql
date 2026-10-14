<?php

declare(strict_types=1);

namespace Merql\Plan;

use Merql\Merge\MergeOperation;
use Merql\Merge\MergeResult;
use Merql\Merge\Conflict;
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
        $conflicts = [];
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
            $rowConflicts = [];
            foreach ($plan->conflicts as $conflict) {
                if ($conflict->table === $operation->table && $conflict->rowKey === $operation->rowKey) {
                    $rowConflicts[] = new Conflict(
                        $conflict->table,
                        $conflict->rowKey,
                        $conflict->type,
                        $conflict->column,
                        $conflict->oursValue,
                        $conflict->theirsValue,
                        $conflict->baseValue,
                    );
                }
            }
            if ($operation->conflictState !== 'clean' && $rowConflicts === []) {
                throw new \RuntimeException('Cannot select an unresolved operation: ' . $operation->id);
            }
            array_push($conflicts, ...$rowConflicts);
        }

        return new MergeResult($operations, $conflicts, $baseSnapshot);
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
