<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\CellMerge;

use Merql\CellMerge\CellMergeConfig;
use Merql\CellMerge\JsonCellMerger;
use Merql\CellMerge\TextCellMerger;
use Merql\Merge\ThreeWayMerge;
use Merql\Schema\TableSchema;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\TableSnapshotData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests cell-level merge through the full ThreeWayMerge pipeline.
 */
final class CellMergeIntegrationTest extends TestCase
{
    #[Test]
    public function text_column_merges_different_lines_cleanly(): void
    {
        $schema = new TableSchema('posts', [
            'id' => 'int',
            'content' => 'text',
        ], ['id']);

        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($schema, [
                ['id' => '1', 'content' => "line1\nline2\nline3"],
            ], ['id']),
        ]);

        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($schema, [
                ['id' => '1', 'content' => "line1\nours_line2\nline3"],
            ], ['id']),
        ]);

        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($schema, [
                ['id' => '1', 'content' => "line1\nline2\ntheirs_line3"],
            ], ['id']),
        ]);

        // Without cell merge: conflict (both changed 'content').
        $withoutCell = new ThreeWayMerge();
        $result = $withoutCell->merge($base, $ours, $theirs);
        $this->assertFalse($result->isClean());

        // With cell merge: clean (different lines).
        $config = CellMergeConfig::auto();
        $withCell = new ThreeWayMerge($config);
        $result = $withCell->merge($base, $ours, $theirs);

        $this->assertTrue($result->isClean());
        $merged = $result->operations()[0]->values['content'];
        $this->assertStringContainsString('ours_line2', $merged);
        $this->assertStringContainsString('theirs_line3', $merged);
    }

    #[Test]
    public function json_column_merges_different_keys_cleanly(): void
    {
        $schema = new TableSchema('config', [
            'id' => 'int',
            'data' => 'json',
        ], ['id']);

        $base = Snapshotter::fromData('base', [
            'config' => new TableSnapshotData($schema, [
                ['id' => '1', 'data' => '{"theme":"light","lang":"en"}'],
            ], ['id']),
        ]);

        $ours = Snapshotter::fromData('ours', [
            'config' => new TableSnapshotData($schema, [
                ['id' => '1', 'data' => '{"theme":"light","lang":"fr"}'],
            ], ['id']),
        ]);

        $theirs = Snapshotter::fromData('theirs', [
            'config' => new TableSnapshotData($schema, [
                ['id' => '1', 'data' => '{"theme":"dark","lang":"en"}'],
            ], ['id']),
        ]);

        // Without cell merge: conflict.
        $withoutCell = new ThreeWayMerge();
        $result = $withoutCell->merge($base, $ours, $theirs);
        $this->assertFalse($result->isClean());

        // With cell merge: clean (different keys).
        $config = CellMergeConfig::auto();
        $withCell = new ThreeWayMerge($config);
        $result = $withCell->merge($base, $ours, $theirs);

        $this->assertTrue($result->isClean());
        $merged = json_decode($result->operations()[0]->values['data'], true);
        $this->assertSame('dark', $merged['theme']);
        $this->assertSame('fr', $merged['lang']);
    }

    #[Test]
    public function varchar_column_still_uses_opaque_merge(): void
    {
        $schema = new TableSchema('posts', [
            'id' => 'int',
            'title' => 'varchar(255)',
        ], ['id']);

        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($schema, [
                ['id' => '1', 'title' => 'Hello'],
            ], ['id']),
        ]);

        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($schema, [
                ['id' => '1', 'title' => 'Ours'],
            ], ['id']),
        ]);

        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($schema, [
                ['id' => '1', 'title' => 'Theirs'],
            ], ['id']),
        ]);

        // varchar uses opaque merger even with auto config. Still conflicts.
        $config = CellMergeConfig::auto();
        $merge = new ThreeWayMerge($config);
        $result = $merge->merge($base, $ours, $theirs);

        $this->assertFalse($result->isClean());
    }

    #[Test]
    public function custom_column_merger_override(): void
    {
        $schema = new TableSchema('posts', [
            'id' => 'int',
            'metadata' => 'varchar(255)',
        ], ['id']);

        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($schema, [
                ['id' => '1', 'metadata' => '{"a":"1","b":"2"}'],
            ], ['id']),
        ]);

        $ours = Snapshotter::fromData('ours', [
            'posts' => new TableSnapshotData($schema, [
                ['id' => '1', 'metadata' => '{"a":"changed","b":"2"}'],
            ], ['id']),
        ]);

        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData($schema, [
                ['id' => '1', 'metadata' => '{"a":"1","b":"changed"}'],
            ], ['id']),
        ]);

        // varchar wouldn't normally use JSON merger, but we override.
        $config = (new CellMergeConfig())
            ->forColumn('metadata', new JsonCellMerger());

        $merge = new ThreeWayMerge($config);
        $result = $merge->merge($base, $ours, $theirs);

        $this->assertTrue($result->isClean());
        $merged = json_decode($result->operations()[0]->values['metadata'], true);
        $this->assertSame('changed', $merged['a']);
        $this->assertSame('changed', $merged['b']);
    }
}
