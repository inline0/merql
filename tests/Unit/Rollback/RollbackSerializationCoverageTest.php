<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Rollback;

use Merql\Merge\MergeOperation;
use Merql\Rollback\RollbackOperation;
use Merql\Rollback\RollbackPlan;
use Merql\Rollback\RollbackPlanSerializer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RollbackSerializationCoverageTest extends TestCase
{
    #[Test]
    public function serializes_and_hydrates_with_fallbacks(): void
    {
        $operation = new RollbackOperation(
            'op',
            new MergeOperation(MergeOperation::TYPE_UPDATE, 'posts', '1', ['id' => '1'], 'rollback'),
            ['id' => '1', 0 => 'ignored'],
            ['id' => '1'],
        );
        $plan = new RollbackPlan(
            'rollback',
            'plan',
            ['op'],
            ['posts'],
            [$operation],
            123,
            'hash',
            ['source' => 'test'],
        );

        $hydrated = RollbackPlanSerializer::fromArray($plan->toArray());

        $this->assertSame($plan->hash, $hydrated->hash);
        $this->assertSame('test', $hydrated->metadata['source']);
        $this->assertSame([], RollbackPlanSerializer::fromArray([
            'selected_operation_ids' => 'bad',
            'tables' => 'bad',
            'operations' => 'bad',
            'metadata' => 'bad',
        ])->operations);
    }

    #[Test]
    public function rejects_non_object_rollback_json(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RollbackPlanSerializer::fromJson('"not-an-object"');
    }

    #[Test]
    public function normalizes_rollback_operation_arrays(): void
    {
        $operation = RollbackOperation::fromArray([
            'source_operation_id' => 'op',
            'inverse_operation' => [
                'type' => 'update',
                'table' => 'posts',
                'row_key' => '1',
                'values' => ['id' => '1', 0 => 'ignored'],
                'source' => 'rollback',
            ],
            'before_row' => 'bad',
            'expected_after_row' => ['id' => '1', 0 => 'ignored'],
        ]);
        $fallback = RollbackOperation::fromArray(['inverse_operation' => 'bad']);

        $this->assertNull($operation->beforeRow);
        $this->assertSame(['id' => '1'], $operation->expectedAfterRow);
        $this->assertSame([], $fallback->inverseOperation->values);
    }
}
