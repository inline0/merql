<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Rollback;

use Merql\Connection;
use Merql\Merge\MergeOperation;
use Merql\Merge\ThreeWayMerge;
use Merql\Plan\MergePlanBuilder;
use Merql\Plan\OperationSelection;
use Merql\Rollback\RollbackDrift;
use Merql\Rollback\RollbackPlanBuilder;
use Merql\Rollback\RollbackPlanApplier;
use Merql\Rollback\RollbackPlanSerializer;
use Merql\Schema\TableSchema;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\TableSnapshotData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RollbackPlanTest extends TestCase
{
    #[Test]
    public function builds_rollback_plan_for_selected_update(): void
    {
        $base = $this->snapshot('base', [['id' => '1', 'title' => 'Hello']]);
        $ours = $this->snapshot('ours', [['id' => '1', 'title' => 'Updated']]);
        $theirs = $base;
        $result = (new ThreeWayMerge())->merge($base, $ours, $theirs);
        $plan = (new MergePlanBuilder())->build('plan', $base, $ours, $theirs, $result, [], 123);
        $selection = OperationSelection::fromIds([$plan->operations[0]->id]);

        $rollback = (new RollbackPlanBuilder())->build(
            'rollback',
            $plan,
            $selection,
            [$plan->operations[0]->id => ['id' => '1', 'title' => 'Hello']],
            [],
            456,
        );

        $this->assertSame('rollback', $rollback->id);
        $this->assertSame([MergeOperation::TYPE_UPDATE], [$rollback->operations[0]->inverseOperation->type]);
        $this->assertSame(['id' => '1', 'title' => 'Hello'], $rollback->operations[0]->inverseOperation->values);
        $this->assertSame(['id' => '1', 'title' => 'Updated'], $rollback->operations[0]->expectedAfterRow);
    }

    #[Test]
    public function detects_rollback_drift(): void
    {
        $base = $this->snapshot('base', [['id' => '1', 'title' => 'Hello']]);
        $ours = $this->snapshot('ours', [['id' => '1', 'title' => 'Updated']]);
        $result = (new ThreeWayMerge())->merge($base, $ours, $base);
        $plan = (new MergePlanBuilder())->build('plan', $base, $ours, $base, $result, [], 123);
        $selection = OperationSelection::fromIds([$plan->operations[0]->id]);
        $rollback = (new RollbackPlanBuilder())->build(
            'rollback',
            $plan,
            $selection,
            [$plan->operations[0]->id => ['id' => '1', 'title' => 'Hello']],
        );

        $drifted = (new RollbackDrift())->driftedOperationIds(
            $rollback,
            [$plan->operations[0]->id => ['id' => '1', 'title' => 'Someone else edited']],
        );

        $this->assertSame([$plan->operations[0]->id], $drifted);
        $this->assertSame([], (new RollbackDrift())->driftedOperationIds(
            $rollback,
            [$plan->operations[0]->id => ['id' => '1', 'title' => 'Updated']],
        ));
    }

    #[Test]
    public function serializes_and_hydrates_rollback_plan(): void
    {
        $base = $this->snapshot('base', [['id' => '1', 'title' => 'Hello']]);
        $ours = $this->snapshot('ours', [['id' => '1', 'title' => 'Updated']]);
        $result = (new ThreeWayMerge())->merge($base, $ours, $base);
        $plan = (new MergePlanBuilder())->build('plan', $base, $ours, $base, $result, [], 123);
        $selection = OperationSelection::fromIds([$plan->operations[0]->id]);
        $rollback = (new RollbackPlanBuilder())->build(
            'rollback',
            $plan,
            $selection,
            [$plan->operations[0]->id => ['id' => '1', 'title' => 'Hello']],
        );

        $hydrated = RollbackPlanSerializer::fromJson(RollbackPlanSerializer::toJson($rollback));

        $this->assertSame($rollback->hash, $hydrated->hash);
        $this->assertSame(
            $rollback->operations[0]->inverseOperation->values,
            $hydrated->operations[0]->inverseOperation->values,
        );
    }

    #[Test]
    public function builds_inverse_insert_and_delete_operations(): void
    {
        $base = $this->snapshot('base', [['id' => '1', 'title' => 'Old']]);
        $ours = $this->snapshot('ours', [['id' => '2', 'title' => 'Inserted']]);
        $result = new \Merql\Merge\MergeResult([
            new MergeOperation(MergeOperation::TYPE_INSERT, 'posts', '2', ['id' => '2', 'title' => 'Inserted']),
            new MergeOperation(MergeOperation::TYPE_DELETE, 'posts', '1', ['id' => '1', 'title' => 'Old']),
        ]);
        $plan = (new MergePlanBuilder())->build('plan', $base, $ours, $base, $result, [], 123);
        $selection = OperationSelection::fromIds([$plan->operations[0]->id, $plan->operations[1]->id]);

        $rollback = (new RollbackPlanBuilder())->build(
            'rollback',
            $plan,
            $selection,
            [
                $plan->operations[0]->id => null,
                $plan->operations[1]->id => ['id' => '1', 'title' => 'Old'],
            ],
        );

        $this->assertSame(MergeOperation::TYPE_DELETE, $rollback->operations[0]->inverseOperation->type);
        $this->assertSame(MergeOperation::TYPE_INSERT, $rollback->operations[1]->inverseOperation->type);
        $this->assertNull($rollback->operations[1]->expectedAfterRow);
        $this->assertSame([], (new RollbackDrift())->driftedOperationIds(
            $rollback,
            [
                $plan->operations[0]->id => ['id' => '2', 'title' => 'Inserted'],
                $plan->operations[1]->id => null,
            ],
        ));

        $skipped = (new RollbackPlanBuilder())->build('empty', $plan, OperationSelection::fromIds([]), []);
        $this->assertSame([], $skipped->operations);
    }

    #[Test]
    public function applies_rollback_plan(): void
    {
        $connection = Connection::sqlite();
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)');
        $pdo->exec("INSERT INTO posts (id, title) VALUES (1, 'Updated')");
        $base = $this->snapshot('base', [['id' => '1', 'title' => 'Hello']]);
        $ours = $this->snapshot('ours', [['id' => '1', 'title' => 'Updated']]);
        $result = (new ThreeWayMerge())->merge($base, $ours, $base);
        $plan = (new MergePlanBuilder())->build('plan', $base, $ours, $base, $result, [], 123);
        $rollback = (new RollbackPlanBuilder())->build(
            'rollback',
            $plan,
            OperationSelection::fromIds([$plan->operations[0]->id]),
            [$plan->operations[0]->id => ['id' => '1', 'title' => 'Hello']],
        );

        $apply = (new RollbackPlanApplier($connection))->apply($rollback, $base);

        $this->assertFalse($apply->hasErrors());
        $this->assertSame('Hello', $pdo->query('SELECT title FROM posts WHERE id = 1')->fetchColumn());
    }

    /**
     * @param list<array<string, scalar|null>> $rows
     */
    private function snapshot(string $name, array $rows): \Merql\Snapshot\Snapshot
    {
        $schema = new TableSchema('posts', ['id' => 'int', 'title' => 'text'], ['id']);

        return Snapshotter::fromData($name, [
            'posts' => new TableSnapshotData($schema, $rows, ['id']),
        ]);
    }
}
