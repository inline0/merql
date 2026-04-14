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
}
