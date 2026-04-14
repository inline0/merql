<?php

declare(strict_types=1);

namespace Merql\Tests\Integration;

use Merql\Apply\Applier;
use Merql\Apply\DryRun;
use Merql\Apply\SqlGenerator;
use Merql\Connection;
use Merql\Diff\Differ;
use Merql\Driver\SqliteDriver;
use Merql\Merge\ConflictPolicy;
use Merql\Merge\ConflictResolver;
use Merql\Merge\ThreeWayMerge;
use Merql\Snapshot\Snapshotter;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Full integration tests against a real SQLite in-memory database.
 * No mocking. Real SQL. Real merge. Real apply.
 */
final class SqliteIntegrationTest extends TestCase
{
    private PDO $pdo;
    private SqliteDriver $driver;

    protected function setUp(): void
    {
        $this->pdo = Connection::sqlite();
        $this->driver = new SqliteDriver();

        $this->pdo->exec('
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                title TEXT NOT NULL,
                content TEXT,
                status TEXT DEFAULT "draft"
            )
        ');
        $this->pdo->exec('
            CREATE TABLE settings (
                key TEXT PRIMARY KEY,
                value TEXT
            )
        ');
    }

    #[Test]
    public function snapshot_captures_real_database(): void
    {
        $this->pdo->exec("INSERT INTO posts VALUES (1, 'Hello', 'Body', 'draft')");
        $this->pdo->exec("INSERT INTO posts VALUES (2, 'World', 'Body2', 'publish')");

        $snapshotter = new Snapshotter($this->pdo, $this->driver);
        $snapshot = $snapshotter->capture('test');

        $this->assertTrue($snapshot->hasTable('posts'));
        $this->assertTrue($snapshot->hasTable('settings'));
        $this->assertSame(2, $snapshot->getTable('posts')->rowCount());
        $this->assertSame(0, $snapshot->getTable('settings')->rowCount());
    }

    #[Test]
    public function snapshot_specific_tables(): void
    {
        $snapshotter = new Snapshotter($this->pdo, $this->driver);
        $snapshot = $snapshotter->capture('test', ['posts']);

        $this->assertTrue($snapshot->hasTable('posts'));
        $this->assertFalse($snapshot->hasTable('settings'));
    }

    #[Test]
    public function diff_detects_real_changes(): void
    {
        $this->pdo->exec("INSERT INTO posts VALUES (1, 'Hello', 'Body', 'draft')");

        $snapshotter = new Snapshotter($this->pdo, $this->driver);
        $base = $snapshotter->capture('base');

        $this->pdo->exec("UPDATE posts SET title = 'Updated' WHERE id = 1");
        $this->pdo->exec("INSERT INTO posts VALUES (2, 'New', 'New Body', 'publish')");

        $current = $snapshotter->capture('current');

        $differ = new Differ();
        $cs = $differ->diff($base, $current);

        $this->assertCount(1, $cs->inserts());
        $this->assertCount(1, $cs->updates());
        $this->assertEmpty($cs->deletes());
    }

    #[Test]
    public function full_merge_and_apply_pipeline(): void
    {
        // Base state.
        $this->pdo->exec("INSERT INTO posts VALUES (1, 'Hello', 'Body', 'draft')");
        $this->pdo->exec("INSERT INTO settings VALUES ('theme', 'light')");

        $snapshotter = new Snapshotter($this->pdo, $this->driver);
        $base = $snapshotter->capture('base');

        // Simulate "ours": update title.
        $this->pdo->exec("UPDATE posts SET content = 'Body v2' WHERE id = 1");
        $ours = $snapshotter->capture('ours');

        // Reset to base state, simulate "theirs": update status + add setting.
        $this->pdo->exec("UPDATE posts SET content = 'Body', title = 'New Title', status = 'publish' WHERE id = 1");
        $this->pdo->exec("INSERT INTO settings VALUES ('lang', 'en')");
        $theirs = $snapshotter->capture('theirs');

        // Merge.
        $merge = new ThreeWayMerge();
        $result = $merge->merge($base, $ours, $theirs);

        $this->assertTrue($result->isClean());
        // title: theirs, content: ours, status: theirs, + settings insert
        $this->assertGreaterThanOrEqual(2, $result->operationCount());

        // Reset DB to ours state and apply.
        $this->pdo->exec("DELETE FROM posts");
        $this->pdo->exec("DELETE FROM settings");
        $this->pdo->exec("INSERT INTO posts VALUES (1, 'Hello', 'Body v2', 'draft')");
        $this->pdo->exec("INSERT INTO settings VALUES ('theme', 'light')");

        $applier = new Applier($this->pdo, $this->driver);
        $applyResult = $applier->apply($result, $base);

        $this->assertFalse($applyResult->hasErrors());

        // Verify final state.
        $stmt = $this->pdo->query('SELECT * FROM posts WHERE id = 1');
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $this->assertSame('New Title', $row['title']);
        $this->assertSame('Body v2', $row['content']);
        $this->assertSame('publish', $row['status']);

        // Settings should have the new entry.
        $stmt = $this->pdo->query("SELECT value FROM settings WHERE key = 'lang'");
        $val = $stmt !== false ? $stmt->fetchColumn() : null;
        $this->assertSame('en', $val);
    }

    #[Test]
    public function conflict_detection_on_real_data(): void
    {
        $this->pdo->exec("INSERT INTO posts VALUES (1, 'Hello', 'Body', 'draft')");

        $snapshotter = new Snapshotter($this->pdo, $this->driver);
        $base = $snapshotter->capture('base');

        // Ours changes title.
        $this->pdo->exec("UPDATE posts SET title = 'Ours Title' WHERE id = 1");
        $ours = $snapshotter->capture('ours');

        // Theirs also changes title (different value).
        $this->pdo->exec("UPDATE posts SET title = 'Theirs Title' WHERE id = 1");
        $theirs = $snapshotter->capture('theirs');

        $merge = new ThreeWayMerge();
        $result = $merge->merge($base, $ours, $theirs);

        $this->assertFalse($result->isClean());
        $this->assertSame(1, $result->conflictCount());
        $this->assertSame('title', $result->conflicts()[0]->column());

        // Resolve with theirs wins.
        $resolved = ConflictResolver::resolve($result, ConflictPolicy::TheirsWins);
        $this->assertTrue($resolved->isClean());
        $this->assertSame('Theirs Title', $resolved->operations()[0]->values['title']);
    }

    #[Test]
    public function dry_run_generates_sqlite_compatible_sql(): void
    {
        $this->pdo->exec("INSERT INTO posts VALUES (1, 'Hello', 'Body', 'draft')");

        $snapshotter = new Snapshotter($this->pdo, $this->driver);
        $base = $snapshotter->capture('base');

        $this->pdo->exec("UPDATE posts SET title = 'Updated' WHERE id = 1");
        $theirs = $snapshotter->capture('theirs');

        $merge = new ThreeWayMerge();
        $result = $merge->merge($base, $base, $theirs);

        $sql = DryRun::generate($result, $base, [], $this->driver);

        $this->assertNotEmpty($sql);
        // SQLite uses double quotes.
        $this->assertStringContainsString('"posts"', $sql[0]);
        $this->assertStringNotContainsString('`', $sql[0]);
    }

    #[Test]
    public function sql_generator_uses_sqlite_quoting(): void
    {
        $this->pdo->exec("INSERT INTO posts VALUES (1, 'Hello', 'Body', 'draft')");

        $snapshotter = new Snapshotter($this->pdo, $this->driver);
        $base = $snapshotter->capture('base');

        $this->pdo->exec("INSERT INTO posts VALUES (2, 'New', 'New Body', 'publish')");
        $theirs = $snapshotter->capture('theirs');

        $merge = new ThreeWayMerge();
        $result = $merge->merge($base, $base, $theirs);

        $stmts = SqlGenerator::generate($result, $base, [], $this->driver);

        $this->assertNotEmpty($stmts);
        $this->assertStringContainsString('"posts"', $stmts[0]['sql']);
    }

    #[Test]
    public function schema_reader_reads_sqlite_schema(): void
    {
        $schema = $this->driver->readSchema($this->pdo, 'posts');

        $this->assertSame('posts', $schema->name);
        $this->assertArrayHasKey('id', $schema->columns);
        $this->assertArrayHasKey('title', $schema->columns);
        $this->assertSame(['id'], $schema->primaryKey);
    }

    #[Test]
    public function lists_sqlite_tables(): void
    {
        $tables = $this->driver->listTables($this->pdo);

        $this->assertContains('posts', $tables);
        $this->assertContains('settings', $tables);
    }

    #[Test]
    public function reads_sqlite_foreign_keys(): void
    {
        $this->pdo->exec('
            CREATE TABLE comments (
                id INTEGER PRIMARY KEY,
                post_id INTEGER REFERENCES posts(id),
                body TEXT
            )
        ');

        $deps = $this->driver->readForeignKeys($this->pdo);

        $this->assertArrayHasKey('comments', $deps);
        $this->assertContains('posts', $deps['comments']);
    }

    #[Test]
    public function reads_sqlite_composite_primary_key(): void
    {
        $this->pdo->exec('
            CREATE TABLE post_meta (
                post_id INTEGER,
                meta_key TEXT,
                meta_value TEXT,
                PRIMARY KEY (post_id, meta_key)
            )
        ');

        $schema = $this->driver->readSchema($this->pdo, 'post_meta');

        $this->assertSame(['post_id', 'meta_key'], $schema->primaryKey);
    }

    #[Test]
    public function reads_sqlite_unique_keys(): void
    {
        $this->pdo->exec('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                email TEXT,
                username TEXT UNIQUE
            )
        ');
        $this->pdo->exec('CREATE UNIQUE INDEX idx_email ON users(email)');

        $schema = $this->driver->readSchema($this->pdo, 'users');

        $this->assertCount(2, $schema->uniqueKeys);
        $this->assertContains(['email'], $schema->uniqueKeys);
        $this->assertContains(['username'], $schema->uniqueKeys);
    }

    #[Test]
    public function natural_key_tables_use_unique_constraint_for_row_identity(): void
    {
        $this->pdo->exec('
            CREATE TABLE users (
                email TEXT UNIQUE,
                name TEXT
            )
        ');
        $this->pdo->exec("INSERT INTO users VALUES ('a@example.com', 'Alice')");

        $snapshotter = new Snapshotter($this->pdo, $this->driver);
        $base = $snapshotter->capture('base');

        $this->pdo->exec("UPDATE users SET name = 'Alicia' WHERE email = 'a@example.com'");
        $current = $snapshotter->capture('current');

        $changeset = (new Differ())->diff($base, $current);

        $this->assertCount(0, $changeset->inserts());
        $this->assertCount(1, $changeset->updates());
        $this->assertCount(0, $changeset->deletes());
    }

    #[Test]
    public function null_values_round_trip_through_sqlite(): void
    {
        $this->pdo->exec("INSERT INTO posts VALUES (1, 'Hello', NULL, 'draft')");

        $snapshotter = new Snapshotter($this->pdo, $this->driver);
        $snapshot = $snapshotter->capture('test');

        $row = $snapshot->getTable('posts')->getRow('1');
        $this->assertNull($row['content']);
    }
}
