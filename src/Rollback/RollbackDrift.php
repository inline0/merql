<?php

declare(strict_types=1);

namespace Merql\Rollback;

use Merql\Snapshot\RowFingerprint;

/**
 * Drift detector for rollback plans.
 */
final class RollbackDrift
{
    /**
     * @param array<string, array<string, scalar|null>|null> $currentRows Operation ID to current live row.
     * @return list<string> Source operation IDs that drifted.
     */
    public function driftedOperationIds(RollbackPlan $plan, array $currentRows): array
    {
        $drifted = [];

        foreach ($plan->operations as $operation) {
            $currentRow = $currentRows[$operation->sourceOperationId] ?? null;

            if (!$this->matches($currentRow, $operation->expectedAfterRow)) {
                $drifted[] = $operation->sourceOperationId;
            }
        }

        return $drifted;
    }

    /**
     * @param array<string, scalar|null>|null $currentRow
     * @param array<string, scalar|null>|null $expectedRow
     */
    private function matches(?array $currentRow, ?array $expectedRow): bool
    {
        if ($currentRow === null || $expectedRow === null) {
            return $currentRow === $expectedRow;
        }

        return RowFingerprint::compute($currentRow) === RowFingerprint::compute($expectedRow);
    }
}
