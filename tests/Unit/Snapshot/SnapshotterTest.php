<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Snapshot;

use Merql\Schema\TableSchema;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\TableSnapshotData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SnapshotterTest extends TestCase
{
    #[Test]
    public function from_data_builds_snapshot_with_correct_fingerprints(): void
    {
        $schema = new TableSchema('posts', ['id' => 'int', 'title' => 'varchar(255)'], ['id']);

        $snapshot = Snapshotter::fromData('test', [
            'posts' => new TableSnapshotData($schema, [
                ['id' => '1', 'title' => 'Hello'],
                ['id' => '2', 'title' => 'World'],
            ], ['id']),
        ]);

        $this->assertSame('test', $snapshot->name);
        $this->assertTrue($snapshot->hasTable('posts'));
        $this->assertSame(2, $snapshot->getTable('posts')->rowCount());
    }

    #[Test]
    public function from_data_uses_identity_columns_for_row_keys(): void
    {
        $schema = new TableSchema('posts', ['id' => 'int', 'title' => 'varchar(255)'], ['id']);

        $snapshot = Snapshotter::fromData('test', [
            'posts' => new TableSnapshotData($schema, [
                ['id' => '42', 'title' => 'Hello'],
            ], ['id']),
        ]);

        $table = $snapshot->getTable('posts');
        $this->assertTrue($table->hasRow('42'));
        $this->assertSame(['id' => '42', 'title' => 'Hello'], $table->getRow('42'));
    }

    #[Test]
    public function from_data_supports_composite_keys(): void
    {
        $schema = new TableSchema(
            'post_meta',
            ['post_id' => 'int', 'meta_key' => 'varchar(255)', 'meta_value' => 'text'],
            ['post_id', 'meta_key'],
        );

        $snapshot = Snapshotter::fromData('test', [
            'post_meta' => new TableSnapshotData($schema, [
                ['post_id' => '1', 'meta_key' => 'color', 'meta_value' => 'red'],
            ], ['post_id', 'meta_key']),
        ]);

        $table = $snapshot->getTable('post_meta');
        $this->assertTrue($table->hasRow("1\x1Fcolor"));
    }

    #[Test]
    public function build_row_key_from_single_column(): void
    {
        $key = Snapshotter::buildRowKey(['id' => '5', 'name' => 'test'], ['id']);
        $this->assertSame('5', $key);
    }

    #[Test]
    public function build_row_key_from_multiple_columns(): void
    {
        $key = Snapshotter::buildRowKey(
            ['post_id' => '1', 'meta_key' => 'color', 'meta_value' => 'red'],
            ['post_id', 'meta_key'],
        );
        $this->assertSame("1\x1Fcolor", $key);
    }
}
