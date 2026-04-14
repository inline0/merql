<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Merge;

use Merql\Merge\ThreeWayMerge;
use Merql\Schema\TableSchema;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\TableSnapshotData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConflictTest extends TestCase
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
            'status' => 'varchar(20)',
        ], ['id']);
    }

    #[Test]
    public function both_update_same_column_different_values(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Welcome', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Greetings', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $result = $this->merge->merge($base, $ours, $theirs);

        $this->assertFalse($result->isClean());
        $this->assertSame(1, $result->conflictCount());

        $conflict = $result->conflicts()[0];
        $this->assertSame('posts', $conflict->table());
        $this->assertSame('title', $conflict->column());
        $this->assertSame('Welcome', $conflict->oursValue());
        $this->assertSame('Greetings', $conflict->theirsValue());
    }

    #[Test]
    public function update_vs_delete_conflict(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Updated', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($this->schema, [], ['id']),
        ]);

        $result = $this->merge->merge($base, $ours, $theirs);

        $this->assertFalse($result->isClean());
        $this->assertSame(1, $result->conflictCount());
        $this->assertSame('update_delete', $result->conflicts()[0]->type());
    }

    #[Test]
    public function delete_vs_update_conflict(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($this->schema, [], ['id']),
        ]);

        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Updated', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $result = $this->merge->merge($base, $ours, $theirs);

        $this->assertFalse($result->isClean());
        $this->assertSame(1, $result->conflictCount());
        $this->assertSame('delete_update', $result->conflicts()[0]->type());
    }

    #[Test]
    public function both_insert_same_pk_conflict(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [], ['id']),
        ]);

        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Mine', 'content' => 'Body A', 'status' => 'draft'],
            ], ['id']),
        ]);

        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Theirs', 'content' => 'Body B', 'status' => 'publish'],
            ], ['id']),
        ]);

        $result = $this->merge->merge($base, $ours, $theirs);

        $this->assertFalse($result->isClean());
        $this->assertSame(1, $result->conflictCount());
        $this->assertSame('insert_insert', $result->conflicts()[0]->type());
    }

    #[Test]
    public function partial_conflict_some_columns_clean(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        // Ours changes title AND content.
        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Our Title', 'content' => 'Our Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        // Theirs changes title (conflict) and status (clean).
        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Their Title', 'content' => 'Body', 'status' => 'publish'],
            ], ['id']),
        ]);

        $result = $this->merge->merge($base, $ours, $theirs);

        // Title conflicts, content is ours only, status is theirs only.
        $this->assertSame(1, $result->conflictCount());
        $this->assertSame('title', $result->conflicts()[0]->column());

        // The merged update should still exist with the clean columns resolved.
        $this->assertSame(1, $result->operationCount());
        $op = $result->operations()[0];
        $this->assertSame('Our Body', $op->values['content']);
        $this->assertSame('publish', $op->values['status']);
    }

    #[Test]
    public function conflict_primary_key_extraction(): void
    {
        $conflict = $this->merge->merge(
            Snapshotter::fromData('base', [
                'posts' => new TableSnapshotData($this->schema, [
                    ['id' => '42', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
                ], ['id']),
            ]),
            Snapshotter::fromData('ours', [
                'posts' => new TableSnapshotData($this->schema, [
                    ['id' => '42', 'title' => 'Ours', 'content' => 'Body', 'status' => 'draft'],
                ], ['id']),
            ]),
            Snapshotter::fromData('theirs', [
                'posts' => new TableSnapshotData($this->schema, [
                    ['id' => '42', 'title' => 'Theirs', 'content' => 'Body', 'status' => 'draft'],
                ], ['id']),
            ]),
        )->conflicts()[0];

        $pk = $conflict->primaryKey(['id']);
        $this->assertSame(['id' => '42'], $pk);
    }
}
