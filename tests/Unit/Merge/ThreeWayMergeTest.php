<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Merge;

use Merql\Merge\MergeOperation;
use Merql\Merge\ThreeWayMerge;
use Merql\Schema\TableSchema;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\TableSnapshotData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ThreeWayMergeTest extends TestCase
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
    public function no_changes_produces_clean_empty_result(): void
    {
        $data = [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ];

        $base = Snapshotter::fromData('base', $data);
        $ours = Snapshotter::fromData('ours', $data);
        $theirs = Snapshotter::fromData('theirs', $data);

        $result = $this->merge->merge($base, $ours, $theirs);

        $this->assertTrue($result->isClean());
        $this->assertSame(0, $result->operationCount());
    }

    #[Test]
    public function theirs_insert_only(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
                ['id' => '2', 'title' => 'New', 'content' => 'New Body', 'status' => 'publish'],
            ], ['id']),
        ]);

        $result = $this->merge->merge($base, $ours, $theirs);

        $this->assertTrue($result->isClean());
        $this->assertSame(1, $result->operationCount());
        $this->assertSame(MergeOperation::TYPE_INSERT, $result->operations()[0]->type);
        $this->assertSame('theirs', $result->operations()[0]->source);
    }

    #[Test]
    public function ours_insert_only(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
                ['id' => '3', 'title' => 'Mine', 'content' => 'Mine Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $result = $this->merge->merge($base, $ours, $theirs);

        $this->assertTrue($result->isClean());
        $this->assertSame(1, $result->operationCount());
        $this->assertSame(MergeOperation::TYPE_INSERT, $result->operations()[0]->type);
        $this->assertSame('ours', $result->operations()[0]->source);
    }

    #[Test]
    public function theirs_update_only(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Updated', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $result = $this->merge->merge($base, $ours, $theirs);

        $this->assertTrue($result->isClean());
        $this->assertSame(1, $result->operationCount());
        $this->assertSame(MergeOperation::TYPE_UPDATE, $result->operations()[0]->type);
        $this->assertSame('theirs', $result->operations()[0]->source);
    }

    #[Test]
    public function theirs_delete_only(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
                ['id' => '2', 'title' => 'Bye', 'content' => 'Body2', 'status' => 'publish'],
            ], ['id']),
        ]);

        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
                ['id' => '2', 'title' => 'Bye', 'content' => 'Body2', 'status' => 'publish'],
            ], ['id']),
        ]);

        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $result = $this->merge->merge($base, $ours, $theirs);

        $this->assertTrue($result->isClean());
        $this->assertSame(1, $result->operationCount());
        $this->assertSame(MergeOperation::TYPE_DELETE, $result->operations()[0]->type);
    }

    #[Test]
    public function both_same_change_no_conflict(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $changed = [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Same Title', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ];

        $ours = Snapshotter::fromData('ours', $changed);
        $theirs = Snapshotter::fromData('theirs', $changed);

        $result = $this->merge->merge($base, $ours, $theirs);

        $this->assertTrue($result->isClean());
        $this->assertSame(1, $result->operationCount());
    }

    #[Test]
    public function column_level_clean_merge(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body v2', 'status' => 'draft'],
            ], ['id']),
        ]);

        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'New Title', 'content' => 'Body', 'status' => 'publish'],
            ], ['id']),
        ]);

        $result = $this->merge->merge($base, $ours, $theirs);

        $this->assertTrue($result->isClean());
        $this->assertSame(1, $result->operationCount());

        $op = $result->operations()[0];
        $this->assertSame(MergeOperation::TYPE_UPDATE, $op->type);
        $this->assertSame('New Title', $op->values['title']);
        $this->assertSame('Body v2', $op->values['content']);
        $this->assertSame('publish', $op->values['status']);
    }

    #[Test]
    public function both_deleted_same_row(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
                ['id' => '2', 'title' => 'Bye', 'content' => 'Body2', 'status' => 'publish'],
            ], ['id']),
        ]);

        $after = [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ];

        $ours = Snapshotter::fromData('ours', $after);
        $theirs = Snapshotter::fromData('theirs', $after);

        $result = $this->merge->merge($base, $ours, $theirs);

        $this->assertTrue($result->isClean());
        $this->assertSame(1, $result->operationCount());
        $this->assertSame(MergeOperation::TYPE_DELETE, $result->operations()[0]->type);
    }

    #[Test]
    public function mixed_no_overlap(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
                ['id' => '3', 'title' => 'Ours New', 'content' => 'Ours Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Updated', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $result = $this->merge->merge($base, $ours, $theirs);

        $this->assertTrue($result->isClean());
        $this->assertSame(2, $result->operationCount());
    }

    #[Test]
    public function ours_update_only(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Updated by us', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $result = $this->merge->merge($base, $ours, $theirs);

        $this->assertTrue($result->isClean());
        $this->assertSame(1, $result->operationCount());
        $this->assertSame(MergeOperation::TYPE_UPDATE, $result->operations()[0]->type);
        $this->assertSame('ours', $result->operations()[0]->source);
        $this->assertSame('Updated by us', $result->operations()[0]->values['title']);
    }

    #[Test]
    public function ours_delete_only(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
                ['id' => '2', 'title' => 'Bye', 'content' => 'Body2', 'status' => 'publish'],
            ], ['id']),
        ]);

        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
                ['id' => '2', 'title' => 'Bye', 'content' => 'Body2', 'status' => 'publish'],
            ], ['id']),
        ]);

        $result = $this->merge->merge($base, $ours, $theirs);

        $this->assertTrue($result->isClean());
        $this->assertSame(1, $result->operationCount());
        $this->assertSame(MergeOperation::TYPE_DELETE, $result->operations()[0]->type);
        $this->assertSame('ours', $result->operations()[0]->source);
    }

    #[Test]
    public function schema_mismatches_detected(): void
    {
        $baseSchema = new TableSchema('posts', [
            'id' => 'int',
            'title' => 'varchar(255)',
        ], ['id']);

        $theirsSchema = new TableSchema('posts', [
            'id' => 'int',
            'title' => 'varchar(255)',
            'subtitle' => 'varchar(255)',
        ], ['id']);

        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($baseSchema, [
                ['id' => '1', 'title' => 'Hello'],
            ], ['id']),
        ]);

        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($baseSchema, [
                ['id' => '1', 'title' => 'Hello'],
            ], ['id']),
        ]);

        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($theirsSchema, [
                ['id' => '1', 'title' => 'Updated', 'subtitle' => 'New'],
            ], ['id']),
        ]);

        $merge = new ThreeWayMerge();
        $result = $merge->merge($base, $ours, $theirs);

        $this->assertNotEmpty($merge->schemaMismatches());
        $this->assertStringContainsString('subtitle', $merge->schemaMismatches()[0]->getMessage());
    }
}
