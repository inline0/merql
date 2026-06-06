<?php

declare(strict_types=1);

namespace Merql\Rollback;

use Merql\Apply\Applier;
use Merql\Apply\ApplyResult;
use Merql\Merge\MergeResult;
use Merql\Snapshot\Snapshot;
use PDO;

/**
 * Applies inverse operations from a rollback plan.
 */
final class RollbackPlanApplier
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function apply(RollbackPlan $plan, ?Snapshot $base = null): ApplyResult
    {
        $operations = array_map(
            static fn(RollbackOperation $operation): \Merql\Merge\MergeOperation => $operation->inverseOperation,
            $plan->operations,
        );

        return (new Applier($this->pdo))->apply(new MergeResult($operations, [], $base));
    }
}
