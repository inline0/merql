<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Apply;

use Merql\Apply\GuardedApplier;
use Merql\Exceptions\ConflictException;
use Merql\Merge\Conflict;
use Merql\Merge\MergeOperation;
use Merql\Merge\MergeResult;
use Merql\Schema\TableSchema;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\TableSnapshotData;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GuardedApplierTest extends TestCase
{
    #[Test]
    public function applies_when_expected_live_row_matches(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)');
        $pdo->exec("INSERT INTO posts (id, title) VALUES (1, 'Hello')");
        $expected = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData(
                new TableSchema('posts', ['id' => 'int', 'title' => 'text'], ['id']),
                [['id' => 1, 'title' => 'Hello']],
                ['id'],
            ),
        ]);

        $result = (new GuardedApplier($pdo))->apply(new MergeResult([
            new MergeOperation(MergeOperation::TYPE_UPDATE, 'posts', '1', ['id' => 1, 'title' => 'Updated']),
        ]), $expected);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(1, $result->rowsAffected());
        $this->assertSame('Updated', $pdo->query('SELECT title FROM posts WHERE id = 1')->fetchColumn());
    }

    #[Test]
    public function reports_precondition_failure_when_live_row_drifted(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)');
        $pdo->exec("INSERT INTO posts (id, title) VALUES (1, 'Someone else')");
        $expected = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData(
                new TableSchema('posts', ['id' => 'int', 'title' => 'text'], ['id']),
                [['id' => 1, 'title' => 'Hello']],
                ['id'],
            ),
        ]);

        $result = (new GuardedApplier($pdo))->apply(new MergeResult([
            new MergeOperation(MergeOperation::TYPE_UPDATE, 'posts', '1', ['id' => 1, 'title' => 'Updated']),
        ]), $expected);

        $this->assertTrue($result->hasErrors());
        $this->assertSame('Someone else', $pdo->query('SELECT title FROM posts WHERE id = 1')->fetchColumn());
    }

    #[Test]
    public function rejects_unclean_merge_results(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $expected = Snapshotter::fromData('theirs', []);

        $this->expectException(ConflictException::class);

        (new GuardedApplier($pdo))->apply(new MergeResult([], [
            new Conflict('posts', '1', 'update_update'),
        ]), $expected);
    }
}
