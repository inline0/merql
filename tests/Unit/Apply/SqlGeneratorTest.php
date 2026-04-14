<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Apply;

use Merql\Apply\SqlGenerator;
use Merql\Merge\MergeOperation;
use Merql\Merge\MergeResult;
use Merql\Schema\TableSchema;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\TableSnapshotData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SqlGeneratorTest extends TestCase
{
    #[Test]
    public function generates_insert_sql(): void
    {
        $result = new MergeResult([
            new MergeOperation(
                MergeOperation::TYPE_INSERT,
                'posts',
                '1',
                ['id' => '1', 'title' => 'Hello', 'status' => 'draft'],
            ),
        ]);

        $stmts = SqlGenerator::generate($result);

        $this->assertCount(1, $stmts);
        $this->assertSame('INSERT INTO `posts` (`id`, `title`, `status`) VALUES (?, ?, ?)', $stmts[0]['sql']);
        $this->assertSame(['1', 'Hello', 'draft'], $stmts[0]['params']);
    }

    #[Test]
    public function generates_update_sql_with_base_snapshot(): void
    {
        $schema = new TableSchema(
            'posts',
            ['id' => 'int', 'title' => 'varchar(255)', 'status' => 'varchar(20)'],
            ['id'],
        );
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($schema, [
                ['id' => '1', 'title' => 'Hello', 'status' => 'draft'],
            ], ['id']),
        ]);

        $result = new MergeResult([
            new MergeOperation(
                MergeOperation::TYPE_UPDATE,
                'posts',
                '1',
                ['id' => '1', 'title' => 'Updated', 'status' => 'publish'],
            ),
        ]);

        $stmts = SqlGenerator::generate($result, $base);

        $this->assertCount(1, $stmts);
        $this->assertStringContainsString('UPDATE `posts` SET', $stmts[0]['sql']);
        $this->assertStringContainsString('WHERE `id` = ?', $stmts[0]['sql']);
    }

    #[Test]
    public function generates_delete_sql_with_base_snapshot(): void
    {
        $schema = new TableSchema('posts', ['id' => 'int', 'title' => 'varchar(255)'], ['id']);
        $base = Snapshotter::fromData('base', [
            'posts' => new TableSnapshotData($schema, [
                ['id' => '1', 'title' => 'Hello'],
            ], ['id']),
        ]);

        $result = new MergeResult([
            new MergeOperation(
                MergeOperation::TYPE_DELETE,
                'posts',
                '1',
                ['id' => '1', 'title' => 'Hello'],
            ),
        ]);

        $stmts = SqlGenerator::generate($result, $base);

        $this->assertCount(1, $stmts);
        $this->assertSame('DELETE FROM `posts` WHERE `id` = ?', $stmts[0]['sql']);
        $this->assertSame(['1'], $stmts[0]['params']);
    }

    #[Test]
    public function uses_embedded_base_snapshot_when_explicit_base_missing(): void
    {
        $schema = new TableSchema(
            'meta',
            ['post_id' => 'int', 'key' => 'varchar(255)', 'val' => 'text'],
            ['post_id', 'key'],
        );
        $base = Snapshotter::fromData('base', [
            'meta' => new TableSnapshotData($schema, [
                ['post_id' => '1', 'key' => 'color', 'val' => 'red'],
            ], ['post_id', 'key']),
        ]);

        $result = new MergeResult([
            new MergeOperation(
                MergeOperation::TYPE_DELETE,
                'meta',
                "1\x1Fcolor",
                [],
            ),
        ], [], $base);

        $stmts = SqlGenerator::generate($result);

        $this->assertCount(1, $stmts);
        $this->assertSame(
            'DELETE FROM `meta` WHERE `post_id` = ? AND `key` = ?',
            $stmts[0]['sql'],
        );
        $this->assertSame(['1', 'color'], $stmts[0]['params']);
    }

    #[Test]
    public function orders_inserts_before_updates_before_deletes(): void
    {
        $result = new MergeResult([
            new MergeOperation(MergeOperation::TYPE_DELETE, 'posts', '3', ['id' => '3']),
            new MergeOperation(MergeOperation::TYPE_INSERT, 'posts', '1', ['id' => '1', 'title' => 'New']),
            new MergeOperation(MergeOperation::TYPE_UPDATE, 'posts', '2', ['id' => '2', 'title' => 'Updated']),
        ]);

        $stmts = SqlGenerator::generate($result);

        $this->assertStringStartsWith('INSERT', $stmts[0]['sql']);
        $this->assertStringStartsWith('UPDATE', $stmts[1]['sql']);
        $this->assertStringStartsWith('DELETE', $stmts[2]['sql']);
    }

    #[Test]
    public function handles_null_values_in_insert(): void
    {
        $result = new MergeResult([
            new MergeOperation(
                MergeOperation::TYPE_INSERT,
                'posts',
                '1',
                ['id' => '1', 'title' => null],
            ),
        ]);

        $stmts = SqlGenerator::generate($result);

        $this->assertSame(['1', null], $stmts[0]['params']);
    }

    #[Test]
    public function skips_update_when_all_columns_are_identity(): void
    {
        $schema = new TableSchema('t', ['id' => 'int'], ['id']);
        $base = Snapshotter::fromData('base', [
            't' => new TableSnapshotData($schema, [['id' => '1']], ['id']),
        ]);

        $result = new MergeResult([
            new MergeOperation(
                MergeOperation::TYPE_UPDATE,
                't',
                '1',
                ['id' => '1'],
            ),
        ]);

        $stmts = SqlGenerator::generate($result, $base);

        $this->assertCount(0, $stmts);
    }

    #[Test]
    public function composite_key_delete(): void
    {
        $schema = new TableSchema(
            'meta',
            ['post_id' => 'int', 'key' => 'varchar(255)', 'val' => 'text'],
            ['post_id', 'key'],
        );
        $base = Snapshotter::fromData('base', [
            'meta' => new TableSnapshotData($schema, [
                ['post_id' => '1', 'key' => 'color', 'val' => 'red'],
            ], ['post_id', 'key']),
        ]);

        $result = new MergeResult([
            new MergeOperation(
                MergeOperation::TYPE_DELETE,
                'meta',
                "1\x1Fcolor",
                ['post_id' => '1', 'key' => 'color', 'val' => 'red'],
            ),
        ]);

        $stmts = SqlGenerator::generate($result, $base);

        $this->assertCount(1, $stmts);
        $this->assertSame(
            'DELETE FROM `meta` WHERE `post_id` = ? AND `key` = ?',
            $stmts[0]['sql'],
        );
        $this->assertSame(['1', 'color'], $stmts[0]['params']);
    }

    #[Test]
    public function fk_ordering_parents_before_children_for_inserts(): void
    {
        $result = new MergeResult([
            new MergeOperation(MergeOperation::TYPE_INSERT, 'comments', '1', ['id' => '1']),
            new MergeOperation(MergeOperation::TYPE_INSERT, 'posts', '1', ['id' => '1']),
        ]);

        $fkDeps = ['comments' => ['posts']];
        $stmts = SqlGenerator::generate($result, null, $fkDeps);

        // posts should come before comments.
        $this->assertStringContainsString('`posts`', $stmts[0]['sql']);
        $this->assertStringContainsString('`comments`', $stmts[1]['sql']);
    }

    #[Test]
    public function fk_ordering_children_before_parents_for_deletes(): void
    {
        $result = new MergeResult([
            new MergeOperation(MergeOperation::TYPE_DELETE, 'posts', '1', ['id' => '1']),
            new MergeOperation(MergeOperation::TYPE_DELETE, 'comments', '1', ['id' => '1']),
        ]);

        $fkDeps = ['comments' => ['posts']];
        $stmts = SqlGenerator::generate($result, null, $fkDeps);

        // comments should be deleted before posts.
        $this->assertStringContainsString('`comments`', $stmts[0]['sql']);
        $this->assertStringContainsString('`posts`', $stmts[1]['sql']);
    }
}
