<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Snapshot;

use Merql\Exceptions\SnapshotException;
use Merql\Schema\TableSchema;
use Merql\Snapshot\Snapshot;
use Merql\Snapshot\SnapshotStore;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\TableSnapshotData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SnapshotStoreTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/merql_test_' . uniqid();
        SnapshotStore::setDirectory($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Clean up temp files.
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
        }
        SnapshotStore::setDirectory('.merql/snapshots');
    }

    #[Test]
    public function save_and_load_round_trip(): void
    {
        $schema = new TableSchema('posts', ['id' => 'int', 'title' => 'varchar(255)'], ['id']);
        $snapshot = Snapshotter::fromData('round-trip', [
            'posts' => new TableSnapshotData($schema, [
                ['id' => '1', 'title' => 'Hello'],
                ['id' => '2', 'title' => 'World'],
            ], ['id']),
        ]);

        SnapshotStore::save($snapshot);
        $loaded = SnapshotStore::load('round-trip');

        $this->assertSame('round-trip', $loaded->name);
        $this->assertTrue($loaded->hasTable('posts'));
        $this->assertSame(2, $loaded->getTable('posts')->rowCount());
        $this->assertSame('Hello', $loaded->getTable('posts')->getRow('1')['title']);
    }

    #[Test]
    public function preserves_null_values(): void
    {
        $schema = new TableSchema('t', ['id' => 'int', 'val' => 'text'], ['id']);
        $snapshot = Snapshotter::fromData('nulls', [
            't' => new TableSnapshotData($schema, [
                ['id' => '1', 'val' => null],
            ], ['id']),
        ]);

        SnapshotStore::save($snapshot);
        $loaded = SnapshotStore::load('nulls');

        $this->assertNull($loaded->getTable('t')->getRow('1')['val']);
    }

    #[Test]
    public function load_nonexistent_throws(): void
    {
        $this->expectException(SnapshotException::class);

        SnapshotStore::load('does-not-exist');
    }

    #[Test]
    public function exists_returns_true_for_saved(): void
    {
        $schema = new TableSchema('t', ['id' => 'int'], ['id']);
        $snapshot = Snapshotter::fromData('check', [
            't' => new TableSnapshotData($schema, [], ['id']),
        ]);

        SnapshotStore::save($snapshot);

        $this->assertTrue(SnapshotStore::exists('check'));
        $this->assertFalse(SnapshotStore::exists('nope'));
    }

    #[Test]
    public function delete_removes_file(): void
    {
        $schema = new TableSchema('t', ['id' => 'int'], ['id']);
        $snapshot = Snapshotter::fromData('deleteme', [
            't' => new TableSnapshotData($schema, [], ['id']),
        ]);

        SnapshotStore::save($snapshot);
        $this->assertTrue(SnapshotStore::exists('deleteme'));

        SnapshotStore::delete('deleteme');
        $this->assertFalse(SnapshotStore::exists('deleteme'));
    }

    #[Test]
    public function creates_directory_if_missing(): void
    {
        $this->assertFalse(is_dir($this->tempDir));

        $schema = new TableSchema('t', ['id' => 'int'], ['id']);
        $snapshot = Snapshotter::fromData('mkdir-test', [
            't' => new TableSnapshotData($schema, [], ['id']),
        ]);

        SnapshotStore::save($snapshot);

        $this->assertTrue(is_dir($this->tempDir));
    }
}
