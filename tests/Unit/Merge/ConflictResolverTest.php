<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Merge;

use Merql\Merge\ConflictPolicy;
use Merql\Merge\ConflictResolver;
use Merql\Merge\MergeOperation;
use Merql\Merge\ThreeWayMerge;
use Merql\Schema\TableSchema;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\TableSnapshotData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConflictResolverTest extends TestCase
{
    private ThreeWayMerge $merge;
    private TableSchema $schema;

    protected function setUp(): void
    {
        $this->merge = new ThreeWayMerge();
        $this->schema = new TableSchema('posts', [
            'id' => 'int',
            'title' => 'varchar(255)',
            'content' => 'text',
        ], ['id']);
    }

    #[Test]
    public function manual_policy_keeps_conflicts(): void
    {
        $result = $this->buildUpdateConflict();
        $resolved = ConflictResolver::resolve($result, ConflictPolicy::Manual);

        $this->assertFalse($resolved->isClean());
    }

    #[Test]
    public function ours_wins_resolves_insert_insert(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [], ['id']),
        ]);
        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Mine', 'content' => 'A'],
            ], ['id']),
        ]);
        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Theirs', 'content' => 'B'],
            ], ['id']),
        ]);

        $result = $this->merge->merge($base, $ours, $theirs);
        $resolved = ConflictResolver::resolve($result, ConflictPolicy::OursWins);

        $this->assertTrue($resolved->isClean());
        $inserts = array_filter($resolved->operations(), fn($op) => $op->type === MergeOperation::TYPE_INSERT);
        $insert = array_values($inserts);
        $this->assertSame('Mine', $insert[0]->values['title']);
    }

    #[Test]
    public function theirs_wins_resolves_insert_insert(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [], ['id']),
        ]);
        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Mine', 'content' => 'A'],
            ], ['id']),
        ]);
        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Theirs', 'content' => 'B'],
            ], ['id']),
        ]);

        $result = $this->merge->merge($base, $ours, $theirs);
        $resolved = ConflictResolver::resolve($result, ConflictPolicy::TheirsWins);

        $this->assertTrue($resolved->isClean());
        $inserts = array_filter($resolved->operations(), fn($op) => $op->type === MergeOperation::TYPE_INSERT);
        $insert = array_values($inserts);
        $this->assertSame('Theirs', $insert[0]->values['title']);
    }

    #[Test]
    public function ours_wins_resolves_update_delete_as_update(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body'],
            ], ['id']),
        ]);
        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Updated', 'content' => 'Body'],
            ], ['id']),
        ]);
        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($this->schema, [], ['id']),
        ]);

        $result = $this->merge->merge($base, $ours, $theirs);
        $resolved = ConflictResolver::resolve($result, ConflictPolicy::OursWins);

        $this->assertTrue($resolved->isClean());
        $updates = array_filter($resolved->operations(), fn($op) => $op->type === MergeOperation::TYPE_UPDATE);
        $this->assertCount(1, $updates);
    }

    #[Test]
    public function theirs_wins_resolves_update_delete_as_delete(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body'],
            ], ['id']),
        ]);
        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Updated', 'content' => 'Body'],
            ], ['id']),
        ]);
        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($this->schema, [], ['id']),
        ]);

        $result = $this->merge->merge($base, $ours, $theirs);
        $resolved = ConflictResolver::resolve($result, ConflictPolicy::TheirsWins);

        $this->assertTrue($resolved->isClean());
        $deletes = array_filter($resolved->operations(), fn($op) => $op->type === MergeOperation::TYPE_DELETE);
        $this->assertCount(1, $deletes);
    }

    #[Test]
    public function resolved_result_preserves_base_snapshot_metadata(): void
    {
        $result = $this->buildUpdateConflict();

        $resolved = ConflictResolver::resolve($result, ConflictPolicy::TheirsWins);

        $this->assertSame($result->baseSnapshot(), $resolved->baseSnapshot());
    }

    private function buildUpdateConflict(): \Merql\Merge\MergeResult
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body'],
            ], ['id']),
        ]);
        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Mine', 'content' => 'Body'],
            ], ['id']),
        ]);
        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Theirs', 'content' => 'Body'],
            ], ['id']),
        ]);

        return $this->merge->merge($base, $ours, $theirs);
    }
}
