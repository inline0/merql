<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Plan;

use Merql\Merge\MergeOperation;
use Merql\Plan\ChangeGroupSelection;
use Merql\Plan\MergePlan;
use Merql\Plan\MergePlanChangeGroup;
use Merql\Plan\MergePlanConflict;
use Merql\Plan\MergePlanOperation;
use Merql\Plan\MergePlanSerializer;
use Merql\Plan\MergePlanSummary;
use Merql\Plan\OperationSelection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlanSerializationCoverageTest extends TestCase
{
    #[Test]
    public function covers_selection_serialization_helpers(): void
    {
        $groupSelection = ChangeGroupSelection::fromArray([
            'change_group_ids' => ['b', 'a', 'a', 1],
        ]);
        $operationSelection = OperationSelection::fromArray([
            'operation_ids' => ['op-b', 'op-a', 'op-a', 1],
        ]);

        $this->assertSame(['a', 'b'], $groupSelection->changeGroupIds);
        $this->assertSame(['op-a', 'op-b'], $operationSelection->operationIds);
        $this->assertSame($groupSelection->hash(), $groupSelection->toArray()['hash']);
        $this->assertSame($operationSelection->hash(), $operationSelection->toArray()['hash']);
        $this->assertSame([], ChangeGroupSelection::fromArray(['change_group_ids' => 'bad'])->changeGroupIds);
        $this->assertSame([], OperationSelection::fromArray(['operation_ids' => 'bad'])->operationIds);
    }

    #[Test]
    public function covers_plan_lookup_and_serialization_fallbacks(): void
    {
        $operation = new MergePlanOperation(
            'op-1',
            MergeOperation::TYPE_UPDATE,
            'posts',
            '1',
            ['id'],
            ['id' => '1'],
            'ours',
            ['id' => '1', 'title' => 'Old'],
            ['id' => '1', 'title' => 'New'],
            ['id' => '1', 'title' => 'Old'],
            ['id' => '1', 'title' => 'New'],
            ['title'],
        );
        $group = new MergePlanChangeGroup(
            'group-1',
            'operation',
            ['name' => 'Post'],
            'posts',
            '1',
            ['op-1'],
            ['posts'],
            ['update' => 1],
        );
        $plan = new MergePlan(
            'plan',
            'base',
            'ours',
            'theirs',
            123,
            ['posts'],
            new MergePlanSummary(1, 1, 0, 0, ['update' => 1], ['posts' => 1]),
            [$group],
            [$operation],
            [],
            [],
            'hash',
        );

        $this->assertSame($operation, $plan->operation('op-1'));
        $this->assertNull($plan->operation('missing'));
        $this->assertSame($group, $plan->changeGroup('group-1'));
        $this->assertNull($plan->changeGroup('missing'));
        $this->assertSame($plan->toArray()['id'], MergePlanSerializer::fromArray($plan->toArray())->id);

        $fallback = MergePlanSerializer::fromArray([
            'summary' => 'bad',
            'tables' => 'bad',
            'change_groups' => 'bad',
            'operations' => 'bad',
            'conflicts' => [['table' => 'posts', 'row_key' => '1', 'type' => 'update_update'], 1],
            'schema_mismatches' => 'bad',
            'metadata' => 'bad',
        ]);

        $this->assertSame(0, $fallback->summary->operationCount);
        $this->assertSame([], $fallback->tables);
        $this->assertSame([], $fallback->metadata);
        $this->assertCount(1, $fallback->conflicts);
    }

    #[Test]
    public function rejects_non_object_plan_json(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MergePlanSerializer::fromJson('"not-an-object"');
    }

    #[Test]
    public function covers_dto_array_normalization(): void
    {
        $group = MergePlanChangeGroup::fromArray([
            'id' => 'group',
            'type' => 'operation',
            'label' => ['name' => 'Post', 0 => 'ignored'],
            'primary_table' => 'posts',
            'primary_row_key' => '1',
            'operation_ids' => ['op', 1],
            'related_tables' => ['posts', 2],
            'operation_counts' => ['update' => '1', 'bad' => 'no'],
            'source_journal_ids' => ['journal', 3],
            'conflict_state' => 'clean',
            'dependency_state' => 'satisfied',
            'sql_preview_summary' => 'UPDATE',
        ]);
        $operation = MergePlanOperation::fromArray([
            'id' => 'op',
            'type' => 'update',
            'table' => 'posts',
            'row_key' => '1',
            'identity_columns' => ['id', 1],
            'identity_values' => ['id' => '1', 0 => 'ignored'],
            'source' => 'ours',
            'base_row' => ['id' => '1', 'title' => 'Old', 0 => 'ignored'],
            'ours_row' => 'bad',
            'theirs_row' => ['id' => '1', 'title' => 'Old'],
            'result_values' => ['id' => '1', 'title' => 'New'],
            'changed_columns' => ['title', 1],
            'conflict_state' => 'clean',
            'sql_preview' => 'UPDATE',
        ]);
        $conflict = MergePlanConflict::fromArray([
            'table' => 'posts',
            'row_key' => '1',
            'type' => 'update_update',
            'column' => 'title',
            'ours_value' => ['title' => 'Ours', 0 => 'ignored'],
            'theirs_value' => new \stdClass(),
            'base_value' => 'Base',
        ]);
        $summary = MergePlanSummary::fromArray([
            'change_group_count' => '1',
            'operation_count' => '1',
            'conflict_count' => '1',
            'schema_mismatch_count' => '0',
            'operation_counts' => ['update' => '1', 'bad' => 'no'],
            'table_counts' => 'bad',
        ]);

        $this->assertSame(['op'], $group->operationIds);
        $this->assertSame(['update' => 1], $group->operationCounts);
        $this->assertNull($operation->oursRow);
        $this->assertSame(['title'], $operation->changedColumns);
        $this->assertSame(['title' => 'Ours'], $conflict->oursValue);
        $this->assertNull($conflict->theirsValue);
        $this->assertSame(1, $summary->operationCount);
        $this->assertSame([], MergePlanSerializer::fromArray(['conflicts' => 'bad'])->conflicts);
        $this->assertSame([], MergePlanChangeGroup::fromArray([
            'label' => 'bad',
            'operation_ids' => 'bad',
            'related_tables' => 'bad',
            'operation_counts' => 'bad',
            'source_journal_ids' => 'bad',
        ])->label);
        $this->assertSame([], MergePlanOperation::fromArray([
            'identity_columns' => 'bad',
            'result_values' => 'bad',
            'changed_columns' => 'bad',
        ])->resultValues);
        $this->assertSame([], MergePlanSummary::fromArray(['operation_counts' => 'bad'])->operationCounts);
    }
}
