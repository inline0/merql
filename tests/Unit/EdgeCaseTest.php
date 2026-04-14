<?php

declare(strict_types=1);

namespace Merql\Tests\Unit;

use Merql\Apply\DryRun;
use Merql\Apply\SqlGenerator;
use Merql\Diff\Differ;
use Merql\Exceptions\SnapshotException;
use Merql\Merge\ColumnMerge;
use Merql\Merge\ConflictPolicy;
use Merql\Merge\ConflictResolver;
use Merql\Merge\MergeOperation;
use Merql\Merge\ThreeWayMerge;
use Merql\Schema\TableSchema;
use Merql\Snapshot\RowFingerprint;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\SnapshotStore;
use Merql\Snapshot\TableSnapshotData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive edge case tests covering all identified vulnerabilities.
 */
final class EdgeCaseTest extends TestCase
{
    // --- Row key separator collision ---

    #[Test]
    public function row_key_with_separator_in_value_does_not_collide(): void
    {
        $row1 = ['a' => "val\x1Fwith", 'b' => 'sep'];
        $row2 = ['a' => 'val', 'b' => "\x1Fwithsep"];

        $key1 = Snapshotter::buildRowKey($row1, ['a', 'b']);
        $key2 = Snapshotter::buildRowKey($row2, ['a', 'b']);

        $this->assertNotSame($key1, $key2);
    }

    #[Test]
    public function row_key_round_trips_with_separator(): void
    {
        $row = ['id' => "has\x1Fsep", 'name' => "also\x1Fhere"];
        $key = Snapshotter::buildRowKey($row, ['id', 'name']);
        $parts = Snapshotter::decodeRowKey($key);

        $this->assertSame("has\x1Fsep", $parts[0]);
        $this->assertSame("also\x1Fhere", $parts[1]);
    }

    #[Test]
    public function row_key_round_trips_with_percent(): void
    {
        $row = ['id' => '100%', 'name' => '%done'];
        $key = Snapshotter::buildRowKey($row, ['id', 'name']);
        $parts = Snapshotter::decodeRowKey($key);

        $this->assertSame('100%', $parts[0]);
        $this->assertSame('%done', $parts[1]);
    }

    // --- NULL in identity columns ---

    #[Test]
    public function null_pk_treated_as_empty_string(): void
    {
        $row = ['id' => null, 'name' => 'Alice'];
        $key = Snapshotter::buildRowKey($row, ['id']);

        $this->assertSame('', $key);
    }

    #[Test]
    public function two_null_pk_rows_collide(): void
    {
        $key1 = Snapshotter::buildRowKey(['id' => null], ['id']);
        $key2 = Snapshotter::buildRowKey(['id' => null], ['id']);

        $this->assertSame($key1, $key2);
    }

    // --- Type coercion edge cases ---

    #[Test]
    public function values_equal_string_zero_vs_int_zero(): void
    {
        $this->assertTrue(Differ::valuesEqual('0', 0));
        $this->assertTrue(Differ::valuesEqual(0, '0'));
    }

    #[Test]
    public function values_equal_float_precision(): void
    {
        // PHP float string representation should match.
        $this->assertTrue(Differ::valuesEqual('3.14', 3.14));
    }

    #[Test]
    public function values_equal_boolean_edge_cases(): void
    {
        $this->assertFalse(Differ::valuesEqual(null, false));
        $this->assertFalse(Differ::valuesEqual(null, 0));
        $this->assertFalse(Differ::valuesEqual(null, ''));
    }

    #[Test]
    public function values_equal_empty_string_vs_zero(): void
    {
        // Both cast to string: "" vs "0".
        $this->assertFalse(Differ::valuesEqual('', '0'));
    }

    // --- Empty snapshot handling ---

    #[Test]
    public function merge_three_empty_snapshots(): void
    {
        $base = Snapshotter::fromData('base', []);
        $ours = Snapshotter::fromData('ours', []);
        $theirs = Snapshotter::fromData('theirs', []);

        $merge = new ThreeWayMerge();
        $result = $merge->merge($base, $ours, $theirs);

        $this->assertTrue($result->isClean());
        $this->assertSame(0, $result->operationCount());
    }

    #[Test]
    public function merge_with_empty_base_both_add_different_tables(): void
    {
        $schemaA = new TableSchema('a', ['id' => 'int'], ['id']);
        $schemaB = new TableSchema('b', ['id' => 'int'], ['id']);

        $base = Snapshotter::fromData('base', []);
        $ours = Snapshotter::fromData('ours', [
            'a' => new TableSnapshotData($schemaA, [['id' => '1']], ['id']),
        ]);
        $theirs = Snapshotter::fromData('theirs', [
            'b' => new TableSnapshotData($schemaB, [['id' => '1']], ['id']),
        ]);

        $merge = new ThreeWayMerge();
        $result = $merge->merge($base, $ours, $theirs);

        $this->assertTrue($result->isClean());
        $this->assertSame(2, $result->operationCount());
    }

    #[Test]
    public function diff_empty_table(): void
    {
        $schema = new TableSchema('t', ['id' => 'int'], ['id']);
        $base = Snapshotter::fromData('base', [
            't' => new TableSnapshotData($schema, [], ['id']),
        ]);
        $current = Snapshotter::fromData('current', [
            't' => new TableSnapshotData($schema, [], ['id']),
        ]);

        $differ = new Differ();
        $cs = $differ->diff($base, $current);

        $this->assertTrue($cs->isEmpty());
    }

    // --- ConflictResolver update_update ---

    #[Test]
    public function ours_wins_resolves_update_update_columns(): void
    {
        $schema = new TableSchema('t', ['id' => 'int', 'title' => 'varchar(255)'], ['id']);

        $base = Snapshotter::fromData('base', [
            't' => new TableSnapshotData($schema, [
                ['id' => '1', 'title' => 'Hello'],
            ], ['id']),
        ]);
        $ours = Snapshotter::fromData('ours', [
            't' => new TableSnapshotData($schema, [
                ['id' => '1', 'title' => 'Mine'],
            ], ['id']),
        ]);
        $theirs = Snapshotter::fromData('theirs', [
            't' => new TableSnapshotData($schema, [
                ['id' => '1', 'title' => 'Theirs'],
            ], ['id']),
        ]);

        $merge = new ThreeWayMerge();
        $result = $merge->merge($base, $ours, $theirs);

        $this->assertFalse($result->isClean());

        $resolved = ConflictResolver::resolve($result, ConflictPolicy::OursWins);
        $this->assertTrue($resolved->isClean());
        $this->assertSame('Mine', $resolved->operations()[0]->values['title']);
    }

    #[Test]
    public function theirs_wins_resolves_update_update_columns(): void
    {
        $schema = new TableSchema('t', ['id' => 'int', 'title' => 'varchar(255)'], ['id']);

        $base = Snapshotter::fromData('base', [
            't' => new TableSnapshotData($schema, [
                ['id' => '1', 'title' => 'Hello'],
            ], ['id']),
        ]);
        $ours = Snapshotter::fromData('ours', [
            't' => new TableSnapshotData($schema, [
                ['id' => '1', 'title' => 'Mine'],
            ], ['id']),
        ]);
        $theirs = Snapshotter::fromData('theirs', [
            't' => new TableSnapshotData($schema, [
                ['id' => '1', 'title' => 'Theirs'],
            ], ['id']),
        ]);

        $merge = new ThreeWayMerge();
        $result = $merge->merge($base, $ours, $theirs);

        $resolved = ConflictResolver::resolve($result, ConflictPolicy::TheirsWins);
        $this->assertTrue($resolved->isClean());
        $this->assertSame('Theirs', $resolved->operations()[0]->values['title']);
    }

    // --- Fingerprint edge cases ---

    #[Test]
    public function fingerprint_separator_in_value_does_not_collide(): void
    {
        $a = RowFingerprint::compute(['col' => "val\x01ue"]);
        $b = RowFingerprint::compute(['col' => 'value']);

        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function fingerprint_empty_row(): void
    {
        $fp = RowFingerprint::compute([]);
        $this->assertNotEmpty($fp);
    }

    // --- SnapshotStore path traversal ---

    #[Test]
    public function snapshot_name_with_path_traversal_rejected(): void
    {
        $this->expectException(SnapshotException::class);

        SnapshotStore::exists('../../../etc/passwd');
    }

    #[Test]
    public function snapshot_name_with_slash_rejected(): void
    {
        $this->expectException(SnapshotException::class);

        SnapshotStore::exists('foo/bar');
    }

    #[Test]
    public function snapshot_name_with_dot_rejected(): void
    {
        $this->expectException(SnapshotException::class);

        SnapshotStore::exists('foo.bar');
    }

    #[Test]
    public function snapshot_name_valid_chars_accepted(): void
    {
        // Should not throw. File won't exist, but validation passes.
        $this->assertFalse(SnapshotStore::exists('valid-name_123'));
    }

    // --- Column merge NULL edge cases ---

    #[Test]
    public function column_merge_ours_null_theirs_value_conflict(): void
    {
        $base = ['col' => 'original'];
        $ours = ['col' => null];
        $theirs = ['col' => 'different'];

        $result = ColumnMerge::merge('t', 'k', $base, $ours, $theirs);

        // Both changed from base: ours to NULL, theirs to 'different'. Conflict.
        $this->assertCount(1, $result['conflicts']);
    }

    #[Test]
    public function column_merge_both_to_null_no_conflict(): void
    {
        $base = ['col' => 'original'];
        $ours = ['col' => null];
        $theirs = ['col' => null];

        $result = ColumnMerge::merge('t', 'k', $base, $ours, $theirs);

        $this->assertEmpty($result['conflicts']);
        $this->assertNull($result['values']['col']);
    }

    #[Test]
    public function column_merge_missing_column_treated_as_null(): void
    {
        $base = ['id' => '1', 'title' => 'Hello'];
        $ours = ['id' => '1', 'title' => 'Hello'];
        $theirs = ['id' => '1', 'title' => 'Hello', 'extra' => 'new'];

        $result = ColumnMerge::merge('t', 'k', $base, $ours, $theirs);

        // 'extra' is new in theirs (null -> 'new'), should be accepted.
        $this->assertEmpty($result['conflicts']);
        $this->assertSame('new', $result['values']['extra']);
    }

    // --- SQL generation edge cases ---

    #[Test]
    public function sql_generator_handles_special_chars_in_values(): void
    {
        $result = new \Merql\Merge\MergeResult([
            new MergeOperation(
                MergeOperation::TYPE_INSERT,
                'posts',
                '1',
                ['id' => '1', 'title' => "O'Reilly & Sons"],
            ),
        ]);

        $stmts = SqlGenerator::generate($result);

        // Values are parameterized, not interpolated.
        $this->assertSame(['1', "O'Reilly & Sons"], $stmts[0]['params']);
        $this->assertStringNotContainsString("O'Reilly", $stmts[0]['sql']);
    }

    #[Test]
    public function dry_run_handles_unicode_and_emoji(): void
    {
        $result = new \Merql\Merge\MergeResult([
            new MergeOperation(
                MergeOperation::TYPE_INSERT,
                'posts',
                '1',
                ['id' => '1', 'title' => 'Hello 🌍 World'],
            ),
        ]);

        $sql = DryRun::generate($result);

        $this->assertStringContainsString('🌍', $sql[0]);
    }

    // --- JSON round-trip key types ---

    #[Test]
    public function snapshot_store_preserves_numeric_string_keys(): void
    {
        $tmpDir = sys_get_temp_dir() . '/merql_edge_' . uniqid();
        SnapshotStore::setDirectory($tmpDir);

        try {
            $schema = new TableSchema('t', ['id' => 'int', 'val' => 'text'], ['id']);
            $snapshot = Snapshotter::fromData('keytest', [
                't' => new TableSnapshotData($schema, [
                    ['id' => '1', 'val' => 'first'],
                    ['id' => '2', 'val' => 'second'],
                ], ['id']),
            ]);

            SnapshotStore::save($snapshot);
            $loaded = SnapshotStore::load('keytest');

            $table = $loaded->getTable('t');
            $this->assertTrue($table->hasRow('1'));
            $this->assertTrue($table->hasRow('2'));
            $this->assertSame('first', $table->getRow('1')['val']);
        } finally {
            $files = glob($tmpDir . '/*');
            foreach ($files ?: [] as $f) {
                unlink($f);
            }
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
            SnapshotStore::setDirectory('.merql/snapshots');
        }
    }

    // --- Both sides add same new table ---

    #[Test]
    public function both_add_same_table_different_rows(): void
    {
        $schema = new TableSchema('newtable', ['id' => 'int', 'val' => 'text'], ['id']);

        $base = Snapshotter::fromData('base', []);
        $ours = Snapshotter::fromData('ours', [
            'newtable' => new TableSnapshotData($schema, [
                ['id' => '1', 'val' => 'ours'],
            ], ['id']),
        ]);
        $theirs = Snapshotter::fromData('theirs', [
            'newtable' => new TableSnapshotData($schema, [
                ['id' => '2', 'val' => 'theirs'],
            ], ['id']),
        ]);

        $merge = new ThreeWayMerge();
        $result = $merge->merge($base, $ours, $theirs);

        // Different PKs, so both inserts are clean.
        $this->assertTrue($result->isClean());
        $this->assertSame(2, $result->operationCount());
    }

    #[Test]
    public function both_add_same_table_same_pk_conflict(): void
    {
        $schema = new TableSchema('newtable', ['id' => 'int', 'val' => 'text'], ['id']);

        $base = Snapshotter::fromData('base', []);
        $ours = Snapshotter::fromData('ours', [
            'newtable' => new TableSnapshotData($schema, [
                ['id' => '1', 'val' => 'ours'],
            ], ['id']),
        ]);
        $theirs = Snapshotter::fromData('theirs', [
            'newtable' => new TableSnapshotData($schema, [
                ['id' => '1', 'val' => 'theirs'],
            ], ['id']),
        ]);

        $merge = new ThreeWayMerge();
        $result = $merge->merge($base, $ours, $theirs);

        $this->assertFalse($result->isClean());
        $this->assertSame(1, $result->conflictCount());
        $this->assertSame('insert_insert', $result->conflicts()[0]->type());
    }

    // --- Table removed by theirs, modified by ours ---

    #[Test]
    public function theirs_removes_table_ours_modifies_creates_conflicts(): void
    {
        $schema = new TableSchema('t', ['id' => 'int', 'val' => 'text'], ['id']);

        $base = Snapshotter::fromData('base', [
            't' => new TableSnapshotData($schema, [
                ['id' => '1', 'val' => 'original'],
            ], ['id']),
        ]);
        $ours = Snapshotter::fromData('ours', [
            't' => new TableSnapshotData($schema, [
                ['id' => '1', 'val' => 'modified'],
            ], ['id']),
        ]);
        // Theirs has the table removed entirely.
        $theirs = Snapshotter::fromData('theirs', []);

        $merge = new ThreeWayMerge();
        $result = $merge->merge($base, $ours, $theirs);

        // Ours updated, theirs deleted: conflict.
        $this->assertFalse($result->isClean());
    }

    // --- Multiple conflicts in same row resolved correctly ---

    #[Test]
    public function multiple_column_conflicts_in_same_row_all_resolved(): void
    {
        $schema = new TableSchema('t', [
            'id' => 'int',
            'a' => 'varchar(255)',
            'b' => 'varchar(255)',
        ], ['id']);

        $base = Snapshotter::fromData('base', [
            't' => new TableSnapshotData($schema, [
                ['id' => '1', 'a' => 'base_a', 'b' => 'base_b'],
            ], ['id']),
        ]);
        $ours = Snapshotter::fromData('ours', [
            't' => new TableSnapshotData($schema, [
                ['id' => '1', 'a' => 'ours_a', 'b' => 'ours_b'],
            ], ['id']),
        ]);
        $theirs = Snapshotter::fromData('theirs', [
            't' => new TableSnapshotData($schema, [
                ['id' => '1', 'a' => 'theirs_a', 'b' => 'theirs_b'],
            ], ['id']),
        ]);

        $merge = new ThreeWayMerge();
        $result = $merge->merge($base, $ours, $theirs);

        $this->assertSame(2, $result->conflictCount());

        $resolved = ConflictResolver::resolve($result, ConflictPolicy::TheirsWins);
        $this->assertTrue($resolved->isClean());
        $this->assertSame('theirs_a', $resolved->operations()[0]->values['a']);
        $this->assertSame('theirs_b', $resolved->operations()[0]->values['b']);
    }
}
