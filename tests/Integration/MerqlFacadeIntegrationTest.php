<?php

declare(strict_types=1);

namespace Merql\Tests\Integration;

use Merql\Connection;
use Merql\Merql;
use Merql\Schema\TableSchema;
use Merql\Snapshot\SnapshotStore;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\TableSnapshotData;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MerqlFacadeIntegrationTest extends TestCase
{
    private PDO $pdo;
    private string $snapshotDir;

    protected function setUp(): void
    {
        $this->pdo = Connection::sqlite();
        $this->snapshotDir = sys_get_temp_dir() . '/merql_facade_' . uniqid('', true);

        mkdir($this->snapshotDir, 0755, true);
        SnapshotStore::setDirectory($this->snapshotDir);
        Merql::reset();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->snapshotDir . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->snapshotDir)) {
            rmdir($this->snapshotDir);
        }

        SnapshotStore::setDirectory('.merql/snapshots');
        Merql::reset();
    }

    #[Test]
    public function apply_uses_base_snapshot_metadata_from_merge_result(): void
    {
        $this->pdo->exec('
            CREATE TABLE post_meta (
                meta_value TEXT,
                post_id INTEGER,
                meta_key TEXT,
                PRIMARY KEY (post_id, meta_key)
            )
        ');
        $this->pdo->exec("INSERT INTO post_meta VALUES ('red', 1, 'color')");

        Merql::init($this->pdo);
        Merql::snapshot('base');
        Merql::snapshot('ours');

        $this->pdo->exec("UPDATE post_meta SET meta_value = 'blue' WHERE post_id = 1 AND meta_key = 'color'");
        Merql::snapshot('theirs');

        $this->pdo->exec("UPDATE post_meta SET meta_value = 'red' WHERE post_id = 1 AND meta_key = 'color'");

        $result = Merql::merge('base', 'ours', 'theirs');
        $applyResult = Merql::apply($result);

        $this->assertFalse($applyResult->hasErrors());
        $this->assertSame(1, $applyResult->rowsAffected());

        $value = $this->pdo
            ->query("SELECT meta_value FROM post_meta WHERE post_id = 1 AND meta_key = 'color'")
            ?->fetchColumn();

        $this->assertSame('blue', $value);
    }

    #[Test]
    public function merge_result_exposes_schema_mismatches_through_facade(): void
    {
        $this->pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)');
        $this->pdo->exec("INSERT INTO posts VALUES (1, 'Hello')");

        Merql::init($this->pdo);
        Merql::snapshot('base');
        Merql::snapshot('ours');

        $theirs = Snapshotter::fromData('theirs', [
            'posts' => new TableSnapshotData(
                new TableSchema(
                    'posts',
                    ['id' => 'int', 'title' => 'text', 'subtitle' => 'text'],
                    ['id'],
                ),
                [['id' => '1', 'title' => 'Hello', 'subtitle' => 'New']],
                ['id'],
            ),
        ]);
        SnapshotStore::save($theirs);

        $result = Merql::merge('base', 'ours', 'theirs');

        $this->assertTrue($result->hasSchemaMismatches());
        $this->assertCount(1, $result->schemaMismatches());
        $this->assertStringContainsString('subtitle', $result->schemaMismatches()[0]->getMessage());
    }
}
