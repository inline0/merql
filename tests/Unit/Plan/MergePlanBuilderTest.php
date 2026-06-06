<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Plan;

use Merql\Merge\ThreeWayMerge;
use Merql\Merge\MergeOperation;
use Merql\Merge\MergeResult;
use Merql\Plan\ChangeGroupSelection;
use Merql\Plan\MergePlanBuilder;
use Merql\Plan\MergePlanSerializer;
use Merql\Plan\SelectedMergeResultFactory;
use Merql\Schema\TableSchema;
use Merql\Snapshot\Snapshot;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\TableSnapshotData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MergePlanBuilderTest extends TestCase
{
    #[Test]
    public function builds_group_first_plan_with_stable_operation_ids(): void
    {
        $base = $this->snapshot('base', [['id' => '1', 'title' => 'Hello', 'status' => 'draft']]);
        $ours = $this->snapshot('ours', [['id' => '1', 'title' => 'Changed', 'status' => 'draft']]);
        $theirs = $this->snapshot('theirs', [['id' => '1', 'title' => 'Hello', 'status' => 'publish']]);
        $result = (new ThreeWayMerge())->merge($base, $ours, $theirs);

        $plan = (new MergePlanBuilder())->build('plan-1', $base, $ours, $theirs, $result, [], 123);
        $samePlan = (new MergePlanBuilder())->build('plan-1', $base, $ours, $theirs, $result, [], 123);

        $this->assertSame('plan-1', $plan->id);
        $this->assertSame(1, $plan->summary->changeGroupCount);
        $this->assertSame(1, $plan->summary->operationCount);
        $this->assertSame($plan->operations[0]->id, $samePlan->operations[0]->id);
        $this->assertSame($plan->changeGroups[0]->id, $samePlan->changeGroups[0]->id);
        $this->assertSame(['status', 'title'], $plan->operations[0]->changedColumns);
        $this->assertSame([$plan->operations[0]->id], $plan->changeGroups[0]->operationIds);
    }

    #[Test]
    public function serializes_and_hydrates_merge_plan(): void
    {
        $base = $this->snapshot('base', [['id' => '1', 'title' => 'Hello']]);
        $ours = $this->snapshot('ours', [['id' => '1', 'title' => 'Updated']]);
        $theirs = $this->snapshot('theirs', [['id' => '1', 'title' => 'Hello']]);
        $result = (new ThreeWayMerge())->merge($base, $ours, $theirs);
        $plan = (new MergePlanBuilder())->build('plan-2', $base, $ours, $theirs, $result, ['source' => 'test'], 123);

        $hydrated = MergePlanSerializer::fromJson(MergePlanSerializer::toJson($plan));

        $this->assertSame($plan->id, $hydrated->id);
        $this->assertSame($plan->hash, $hydrated->hash);
        $this->assertSame($plan->operations[0]->id, $hydrated->operations[0]->id);
        $this->assertSame('test', $hydrated->metadata['source']);
    }

    #[Test]
    public function resolves_change_group_selection_to_selected_merge_result(): void
    {
        $base = $this->snapshot('base', [
            ['id' => '1', 'title' => 'Hello'],
            ['id' => '2', 'title' => 'World'],
        ]);
        $ours = $this->snapshot('ours', [
            ['id' => '1', 'title' => 'One'],
            ['id' => '2', 'title' => 'Two'],
        ]);
        $theirs = $base;
        $result = (new ThreeWayMerge())->merge($base, $ours, $theirs);
        $plan = (new MergePlanBuilder())->build('plan-3', $base, $ours, $theirs, $result, [], 123);
        $selection = ChangeGroupSelection::fromIds([$plan->changeGroups[0]->id]);

        $selected = (new SelectedMergeResultFactory())->fromChangeGroupSelection($plan, $selection, $base);

        $this->assertCount(1, $selected->operations());
        $this->assertSame($plan->operations[0]->rowKey, $selected->operations()[0]->rowKey);
        $this->assertSame($selection->toOperationSelection($plan)->operationIds, [$plan->operations[0]->id]);
    }

    #[Test]
    public function builds_conflicts_and_handles_missing_base_rows(): void
    {
        $empty = $this->snapshot('base', []);
        $ours = $this->snapshot('ours', [['id' => '1', 'title' => 'Ours']]);
        $theirs = $this->snapshot('theirs', [['id' => '1', 'title' => 'Theirs']]);
        $result = (new ThreeWayMerge())->merge($empty, $ours, $theirs);

        $plan = (new MergePlanBuilder())->build('conflict', $empty, $ours, $theirs, $result, [], 123);

        $this->assertSame(1, $plan->summary->conflictCount);
        $this->assertSame('insert_insert', $plan->conflicts[0]->type);
    }

    #[Test]
    public function uses_ours_or_theirs_identity_when_base_table_is_missing(): void
    {
        $empty = new Snapshot('empty', []);
        $ours = $this->snapshot('ours', [['id' => '1', 'title' => 'Ours']]);
        $theirs = new Snapshot('theirs', [
            'theirs_only' => $this->snapshot('theirs_table', [['id' => '2', 'title' => 'Theirs']])->getTable('posts'),
        ]);
        $result = new MergeResult([
            new MergeOperation(MergeOperation::TYPE_INSERT, 'posts', '1', ['id' => '1', 'title' => 'Ours']),
            new MergeOperation(MergeOperation::TYPE_INSERT, 'theirs_only', '2', ['id' => '2', 'title' => 'Theirs']),
            new MergeOperation(MergeOperation::TYPE_INSERT, 'missing', '1', ['id' => '1']),
        ]);

        $plan = (new MergePlanBuilder())->build('fallbacks', $empty, $ours, $theirs, $result, [], 123);

        $this->assertSame(['id'], $plan->operations[0]->identityColumns);
        $this->assertSame(['id'], $plan->operations[1]->identityColumns);
        $this->assertSame([], $plan->operations[2]->identityColumns);
        $this->assertSame(['id', 'title'], $plan->operations[0]->changedColumns);
    }

    /**
     * @param list<array<string, scalar|null>> $rows
     */
    private function snapshot(string $name, array $rows): Snapshot
    {
        $schema = new TableSchema('posts', ['id' => 'int', 'title' => 'text', 'status' => 'text'], ['id']);

        return Snapshotter::fromData($name, [
            'posts' => new TableSnapshotData($schema, $rows, ['id']),
        ]);
    }
}
