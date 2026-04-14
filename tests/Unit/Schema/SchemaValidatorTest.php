<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Schema;

use Merql\Schema\SchemaValidator;
use Merql\Schema\TableSchema;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\TableSnapshotData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorTest extends TestCase
{
    #[Test]
    public function no_mismatch_when_schemas_identical(): void
    {
        $schema = new TableSchema('t', ['id' => 'int', 'name' => 'varchar(255)'], ['id']);
        $data = ['t' => new TableSnapshotData($schema, [], ['id'])];

        $base = Snapshotter::fromData('base', $data);
        $ours = Snapshotter::fromData('ours', $data);
        $theirs = Snapshotter::fromData('theirs', $data);

        $mismatches = SchemaValidator::validate($base, $ours, $theirs);

        $this->assertEmpty($mismatches);
    }

    #[Test]
    public function detects_column_added_in_theirs(): void
    {
        $baseSchema = new TableSchema('t', ['id' => 'int', 'name' => 'varchar(255)'], ['id']);
        $theirsSchema = new TableSchema('t', ['id' => 'int', 'name' => 'varchar(255)', 'extra' => 'text'], ['id']);

        $base = Snapshotter::fromData('base', ['t' => new TableSnapshotData($baseSchema, [], ['id'])]);
        $ours = Snapshotter::fromData('ours', ['t' => new TableSnapshotData($baseSchema, [], ['id'])]);
        $theirs = Snapshotter::fromData('theirs', ['t' => new TableSnapshotData($theirsSchema, [], ['id'])]);

        $mismatches = SchemaValidator::validate($base, $ours, $theirs);

        $this->assertCount(1, $mismatches);
        $this->assertStringContainsString('added in theirs', $mismatches[0]->getMessage());
        $this->assertStringContainsString('extra', $mismatches[0]->getMessage());
    }

    #[Test]
    public function detects_column_removed_in_ours(): void
    {
        $baseSchema = new TableSchema('t', ['id' => 'int', 'name' => 'varchar(255)', 'old' => 'text'], ['id']);
        $oursSchema = new TableSchema('t', ['id' => 'int', 'name' => 'varchar(255)'], ['id']);

        $base = Snapshotter::fromData('base', ['t' => new TableSnapshotData($baseSchema, [], ['id'])]);
        $ours = Snapshotter::fromData('ours', ['t' => new TableSnapshotData($oursSchema, [], ['id'])]);
        $theirs = Snapshotter::fromData('theirs', ['t' => new TableSnapshotData($baseSchema, [], ['id'])]);

        $mismatches = SchemaValidator::validate($base, $ours, $theirs);

        $this->assertCount(1, $mismatches);
        $this->assertStringContainsString('removed in ours', $mismatches[0]->getMessage());
    }

    #[Test]
    public function detects_multiple_mismatches(): void
    {
        $baseSchema = new TableSchema('t', ['id' => 'int', 'name' => 'varchar(255)'], ['id']);
        $oursSchema = new TableSchema('t', ['id' => 'int', 'name' => 'varchar(255)', 'a' => 'text'], ['id']);
        $theirsSchema = new TableSchema('t', ['id' => 'int', 'name' => 'varchar(255)', 'b' => 'text'], ['id']);

        $base = Snapshotter::fromData('base', ['t' => new TableSnapshotData($baseSchema, [], ['id'])]);
        $ours = Snapshotter::fromData('ours', ['t' => new TableSnapshotData($oursSchema, [], ['id'])]);
        $theirs = Snapshotter::fromData('theirs', ['t' => new TableSnapshotData($theirsSchema, [], ['id'])]);

        $mismatches = SchemaValidator::validate($base, $ours, $theirs);

        $this->assertCount(2, $mismatches);
    }

    #[Test]
    public function no_mismatch_when_table_only_in_one_snapshot(): void
    {
        $schema = new TableSchema('t', ['id' => 'int'], ['id']);

        $base = Snapshotter::fromData('base', []);
        $ours = Snapshotter::fromData('ours', []);
        $theirs = Snapshotter::fromData('theirs', ['t' => new TableSnapshotData($schema, [], ['id'])]);

        $mismatches = SchemaValidator::validate($base, $ours, $theirs);

        $this->assertEmpty($mismatches);
    }
}
