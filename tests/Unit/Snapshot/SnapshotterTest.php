<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Snapshot;

use Merql\Connection;
use Merql\Exceptions\SnapshotException;
use Merql\Identity\IdentityRule;
use Merql\Identity\IdentityRuleSet;
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
    public function from_data_rejects_ambiguous_identity_keys(): void
    {
        $this->expectException(SnapshotException::class);
        $this->expectExceptionMessage("Snapshot identity for table 'options' is ambiguous");

        Snapshotter::fromData('test', [
            'options' => new TableSnapshotData(
                new TableSchema('options', ['option_name' => 'varchar(255)'], []),
                [
                    ['option_name' => 'siteurl'],
                    ['option_name' => 'siteurl'],
                ],
                ['option_name'],
            ),
        ]);
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

    #[Test]
    public function captures_physical_tables_under_canonical_names(): void
    {
        $connection = Connection::sqlite();
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE sandbox_posts (id INTEGER PRIMARY KEY, title TEXT)');
        $pdo->exec("INSERT INTO sandbox_posts (id, title) VALUES (1, 'Hello')");

        $snapshot = (new Snapshotter($connection))->captureAliased('ours', [
            'sandbox_posts' => 'wp_posts',
        ]);

        $this->assertFalse($snapshot->hasTable('sandbox_posts'));
        $this->assertTrue($snapshot->hasTable('wp_posts'));
        $this->assertSame('wp_posts', $snapshot->getTable('wp_posts')->schema->name);
        $this->assertSame(['id' => '1', 'title' => 'Hello'], $snapshot->getTable('wp_posts')->getRow('1'));
    }

    #[Test]
    public function capture_uses_explicit_identity_rules(): void
    {
        $connection = Connection::sqlite();
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE options (option_id INTEGER PRIMARY KEY, option_name TEXT, option_value TEXT)');
        $pdo->exec(
            "INSERT INTO options (option_id, option_name, option_value) VALUES (1, 'siteurl', 'https://example.test')",
        );

        $rules = new IdentityRuleSet([
            'options' => IdentityRule::natural(['option_name']),
        ]);

        $snapshot = (new Snapshotter($connection, identityRules: $rules))->capture('ours', ['options']);
        $table = $snapshot->getTable('options');

        $this->assertSame(['option_name'], $table->identityColumns);
        $this->assertTrue($table->hasRow('siteurl'));
        $this->assertFalse($table->hasRow('1'));
    }

    #[Test]
    public function capture_rejects_ambiguous_explicit_identity_rules(): void
    {
        $connection = Connection::sqlite();
        $pdo = $connection->pdo();
        $pdo->exec(
            'CREATE TABLE postmeta (meta_id INTEGER PRIMARY KEY, post_id INTEGER, meta_key TEXT, meta_value TEXT)',
        );
        $pdo->exec("INSERT INTO postmeta (meta_id, post_id, meta_key, meta_value) VALUES (1, 10, 'color', 'red')");
        $pdo->exec("INSERT INTO postmeta (meta_id, post_id, meta_key, meta_value) VALUES (2, 10, 'color', 'blue')");

        $rules = new IdentityRuleSet([
            'postmeta' => IdentityRule::natural(['post_id', 'meta_key']),
        ]);

        $this->expectException(SnapshotException::class);
        $this->expectExceptionMessage("Snapshot identity for table 'postmeta' is ambiguous");

        (new Snapshotter($connection, identityRules: $rules))->capture('ours', ['postmeta']);
    }
}
