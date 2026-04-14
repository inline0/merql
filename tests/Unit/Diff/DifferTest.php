<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Diff;

use Merql\Diff\Differ;
use Merql\Schema\TableSchema;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\TableSnapshotData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DifferTest extends TestCase
{
    private Differ $differ;
    private TableSchema $schema;

    protected function setUp(): void
    {
        $this->differ = new Differ();
        $this->schema = new TableSchema('posts', [
            'id' => 'int',
            'title' => 'varchar(255)',
            'content' => 'text',
            'status' => 'varchar(20)',
        ], ['id']);
    }

    #[Test]
    public function detects_inserts(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $current = Snapshotter::fromData('current', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
                ['id' => '2', 'title' => 'New Post', 'content' => 'New Body', 'status' => 'publish'],
            ], ['id']),
        ]);

        $changeset = $this->differ->diff($base, $current);

        $this->assertCount(1, $changeset->inserts());
        $this->assertEmpty($changeset->updates());
        $this->assertEmpty($changeset->deletes());
        $this->assertSame('2', $changeset->inserts()[0]->rowKey);
        $this->assertSame('New Post', $changeset->inserts()[0]->values['title']);
    }

    #[Test]
    public function detects_deletes(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
                ['id' => '2', 'title' => 'Goodbye', 'content' => 'Body2', 'status' => 'publish'],
            ], ['id']),
        ]);

        $current = Snapshotter::fromData('current', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $changeset = $this->differ->diff($base, $current);

        $this->assertEmpty($changeset->inserts());
        $this->assertEmpty($changeset->updates());
        $this->assertCount(1, $changeset->deletes());
        $this->assertSame('2', $changeset->deletes()[0]->rowKey);
    }

    #[Test]
    public function detects_updates_with_column_diffs(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $current = Snapshotter::fromData('current', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Updated', 'content' => 'Body', 'status' => 'publish'],
            ], ['id']),
        ]);

        $changeset = $this->differ->diff($base, $current);

        $this->assertEmpty($changeset->inserts());
        $this->assertCount(1, $changeset->updates());
        $this->assertEmpty($changeset->deletes());

        $update = $changeset->updates()[0];
        $this->assertSame('1', $update->rowKey);
        $this->assertCount(2, $update->columnDiffs);

        $diffMap = $update->columnDiffMap();
        $this->assertArrayHasKey('title', $diffMap);
        $this->assertSame('Hello', $diffMap['title']->oldValue);
        $this->assertSame('Updated', $diffMap['title']->newValue);
        $this->assertArrayHasKey('status', $diffMap);
    }

    #[Test]
    public function no_changes_produces_empty_changeset(): void
    {
        $data = [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ];

        $base = Snapshotter::fromData('base', $data);
        $current = Snapshotter::fromData('current', $data);

        $changeset = $this->differ->diff($base, $current);

        $this->assertTrue($changeset->isEmpty());
        $this->assertSame(0, $changeset->count());
    }

    #[Test]
    public function detects_null_to_value_update(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => null, 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $current = Snapshotter::fromData('current', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Now Set', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $changeset = $this->differ->diff($base, $current);

        $this->assertCount(1, $changeset->updates());
        $diffMap = $changeset->updates()[0]->columnDiffMap();
        $this->assertNull($diffMap['title']->oldValue);
        $this->assertSame('Now Set', $diffMap['title']->newValue);
    }

    #[Test]
    public function detects_value_to_null_update(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $current = Snapshotter::fromData('current', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => null, 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $changeset = $this->differ->diff($base, $current);

        $this->assertCount(1, $changeset->updates());
        $diffMap = $changeset->updates()[0]->columnDiffMap();
        $this->assertSame('Hello', $diffMap['title']->oldValue);
        $this->assertNull($diffMap['title']->newValue);
    }

    #[Test]
    public function for_table_filters_operations(): void
    {
        $schema2 = new TableSchema('settings', ['key' => 'varchar(255)', 'value' => 'text'], ['key']);

        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
            'settings' => new TableSnapshotData($schema2, [
                ['key' => 'site_name', 'value' => 'Old'],
            ], ['key']),
        ]);

        $current = Snapshotter::fromData('current', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Updated', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
            'settings' => new TableSnapshotData($schema2, [
                ['key' => 'site_name', 'value' => 'New'],
            ], ['key']),
        ]);

        $changeset = $this->differ->diff($base, $current);
        $postsOnly = $changeset->forTable('posts');

        $this->assertSame(1, $postsOnly->count());
        $this->assertSame('posts', $postsOnly->updates()[0]->table);
    }

    #[Test]
    public function values_equal_handles_null(): void
    {
        $this->assertTrue(Differ::valuesEqual(null, null));
        $this->assertFalse(Differ::valuesEqual(null, ''));
        $this->assertFalse(Differ::valuesEqual('', null));
        $this->assertTrue(Differ::valuesEqual('hello', 'hello'));
        $this->assertFalse(Differ::valuesEqual('hello', 'world'));
    }

    #[Test]
    public function detects_new_table_as_inserts(): void
    {
        $base = Snapshotter::fromData('base', []);

        $current = Snapshotter::fromData('current', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $changeset = $this->differ->diff($base, $current);

        $this->assertCount(1, $changeset->inserts());
        $this->assertSame('posts', $changeset->inserts()[0]->table);
    }

    #[Test]
    public function detects_removed_table_as_deletes(): void
    {
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($this->schema, [
                ['id' => '1', 'title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
            ], ['id']),
        ]);

        $current = Snapshotter::fromData('current', []);

        $changeset = $this->differ->diff($base, $current);

        $this->assertCount(1, $changeset->deletes());
        $this->assertSame('posts', $changeset->deletes()[0]->table);
    }
}
