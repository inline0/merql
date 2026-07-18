---
title: "API"
description: "Complete PHP API reference for merql. Covers the facade, snapshotter, differ, merge engine, applier, cell mergers, drivers, filters, and exceptions."
path: "api"
order: 180
section: "Reference"
meta_title: "API"
meta_description: "Complete PHP API reference for merql. Covers the facade, snapshotter, differ, merge engine, applier, cell mergers, drivers, filters, and exceptions."
---

# PHP API Reference

Full reference for all public classes and methods in merql.

## Merql (facade)

`Merql\Merql` is the static entry point for common operations. It wraps the lower-level classes and manages snapshot persistence automatically.

```php
use Merql\Connection;
use Merql\Identity\IdentityRule;
use Merql\Identity\IdentityRuleSet;
use Merql\Merql;

$connection = Connection::sqlite(':memory:');
Merql::init($connection, identityRules: new IdentityRuleSet([
    'options' => IdentityRule::natural(['option_name']),
]));
```

### `init(DatabaseConnection $connection, ?Driver $driver = null, ?IdentityRuleSet $identityRules = null): void`

Initialize the facade with a database connection. Must be called before
`snapshot()` or `apply()`. Pass a driver to override auto-detection, or an
identity rule set to override per-table row identity during snapshot capture.

### `snapshot(string $name, array $tables = []): Snapshot`

Capture the current database state and persist it to disk. Pass an array of table names to snapshot specific tables, or omit for all tables.

```php
$snapshot = Merql::snapshot('baseline');
$snapshot = Merql::snapshot('baseline', ['posts', 'users']);
```

### `diff(string $base, string $current): Changeset`

Load two named snapshots and compute the changeset between them. Does not require `init()` since it works from persisted snapshots.

```php
$changeset = Merql::diff('before', 'after');
```

### `merge(string $base, string $ours, string $theirs): MergeResult`

Three-way merge of three named snapshots. Returns a result containing clean operations and any conflicts.

```php
$result = Merql::merge('base', 'ours', 'theirs');
```

### `patch(string $base, string $changes): MergeResult`

Two-way merge: apply changes onto base. Equivalent to `merge($base, $base, $changes)`. Always produces a clean result because there are no concurrent edits.

```php
$result = Merql::patch('base', 'changes');
```

### `apply(MergeResult $result): ApplyResult`

Execute a clean merge result against the database. Requires `init()`. Throws `ConflictException` if unresolved conflicts remain.

```php
$applied = Merql::apply($result);
echo $applied->rowsAffected();
```

### `plan(string $id, string $base, string $ours, string $theirs, array $metadata = []): MergePlan`

Build a UI-ready merge plan from three named snapshots. The plan includes stable
operation IDs, change group IDs, operation context, conflicts, schema
mismatches, a summary, metadata, and a plan hash.

```php
$plan = Merql::plan('plan-1', 'base', 'ours', 'theirs', [
    'source' => 'review-ui',
]);
```

### `applyGuarded(MergeResult $result, Snapshot $expectedLive): ApplyResult`

Apply a clean merge result with optimistic live-row preconditions. Updates and
deletes require current live rows to match `$expectedLive`; inserts require that
the target identity does not already exist.

```php
use Merql\Snapshot\SnapshotStore;

$applied = Merql::applyGuarded($selectedResult, SnapshotStore::load('theirs'));
```

### `reset(): void`

Clear the internal connection and Snapshotter instances. Primarily used in tests to reset state between runs.

---

## Connection

`Merql\Connection` builds connection adapters with sensible defaults. PDO
connections use exceptions on error, associative fetch mode, no emulated
prepares, and stringified fetches. The mysqli adapter normalizes fetched values
to the same string-or-null shape.

### `mysql(string $host, string $database, string $username, string $password = '', int $port = 3306, string $charset = 'utf8mb4'): PdoDatabaseConnection`

```php
use Merql\Connection;

$connection = Connection::mysql('127.0.0.1', 'my_app', 'root', 'secret');
```

### `mysqli(string $host, string $database, string $username, string $password = '', int $port = 3306, string $charset = 'utf8mb4'): MysqliDatabaseConnection`

```php
$connection = Connection::mysqli('127.0.0.1', 'my_app', 'root', 'secret');
```

### `sqlite(string $path = ':memory:'): PdoDatabaseConnection`

Creates a SQLite connection. Enables foreign keys automatically via `PRAGMA foreign_keys = ON`.

```php
$connection = Connection::sqlite('/var/data/app.sqlite');
$connection = Connection::sqlite(':memory:');
```

### `fromDsn(string $dsn, string $username = '', string $password = ''): PdoDatabaseConnection`

Connect using any PDO DSN string.

```php
$connection = Connection::fromDsn('mysql:host=localhost;dbname=app', 'root', 'pass');
```

### `fromPdo(PDO $pdo): PdoDatabaseConnection`

Wrap an existing PDO instance.

### `fromMysqli(mysqli $mysqli, string $charset = 'utf8mb4'): MysqliDatabaseConnection`

Wrap an existing mysqli instance.

### Adapter contract

All adapters implement `Merql\Database\DatabaseConnection`:

```php
interface DatabaseConnection
{
    public function driverName(): string;
    public function execute(string $sql, array $params = []): int;
    public function query(string $sql, array $params = []): array;
    public function scalar(string $sql, array $params = []): ?string;
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollBack(): void;
    public function lastInsertId(): string;
}
```

`execute()`, `query()`, and `scalar()` accept positional `list<scalar|null>`
parameters for `?` placeholders. `query()` returns
`list<array<string, scalar|null>>`; values are normalized to the same
string-or-null shape across PDO and mysqli adapters.

---

## Snapshotter

`Merql\Snapshot\Snapshotter` captures database state. Unlike the facade, it gives you full control over filters and does not auto-persist.

### `__construct(DatabaseConnection $connection, ?Driver $driver = null, ?IdentityRuleSet $identityRules = null)`

The driver is auto-detected from the connection identity if not provided. Pass an
`IdentityRuleSet` when table-specific row identity should override schema
defaults.

### `capture(string $name, array $tables = [], array $filters = []): Snapshot`

Capture current state. Pass table names to limit scope. Pass filter objects to exclude tables, columns, or rows.

```php
use Merql\Snapshot\Snapshotter;
use Merql\Filter\TableFilter;
use Merql\Filter\ColumnFilter;
use Merql\Filter\RowFilter;
use Merql\Identity\IdentityRule;
use Merql\Identity\IdentityRuleSet;

$snapshotter = new Snapshotter(
    $connection,
    identityRules: new IdentityRuleSet([
        'wp_options' => IdentityRule::natural(['option_name']),
    ]),
);

// All tables.
$snapshot = $snapshotter->capture('full');

// Specific tables with filters.
$snapshot = $snapshotter->capture('filtered', ['posts', 'users'], [
    TableFilter::exclude(['cache_*', 'sessions']),
    ColumnFilter::ignore(['updated_at', 'created_at']),
    RowFilter::create(fn(string $table, array $row) => $row['status'] !== 'trash'),
]);
```

### `captureAliased(string $name, array $physicalToCanonicalTableMap, array $filters = []): Snapshot`

Capture physical tables under canonical table names. Use this when the same
logical table has different physical names across environments.

```php
$snapshot = $snapshotter->captureAliased('sandbox', [
    'wp_onumia_abcd_posts' => 'wp_posts',
    'wp_onumia_abcd_postmeta' => 'wp_postmeta',
]);
```

The resulting snapshot stores canonical table names while preserving the
physical table schema, configured identity columns, row keys, and filters.

### `fromData(string $name, array $tableData): Snapshot` (static)

Build a snapshot from raw data without a database connection. Useful for testing or when importing data from another source.

```php
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\TableSnapshotData;
use Merql\Schema\TableSchema;

$schema = new TableSchema('posts', ['id' => 'int', 'title' => 'varchar'], ['id']);

$snapshot = Snapshotter::fromData('test', [
    'posts' => new TableSnapshotData($schema, [
        ['id' => '1', 'title' => 'Hello'],
        ['id' => '2', 'title' => 'World'],
    ], ['id']),
]);
```

---

## Snapshot

`Merql\Snapshot\Snapshot` is a readonly value object representing a complete database state.

```php
$snapshot->name;                    // string: snapshot identifier
$snapshot->tables;                  // array<string, TableSnapshot>
$snapshot->tableNames();            // list<string>
$snapshot->hasTable('posts');       // bool
$snapshot->getTable('posts');       // ?TableSnapshot
```

---

## TableSnapshot

`Merql\Snapshot\TableSnapshot` holds one table's rows, fingerprints, and schema.

```php
$table = $snapshot->getTable('posts');

$table->schema;                    // TableSchema
$table->identityColumns;           // list<string>: columns used for row identity
$table->fingerprints;              // array<string, string>: row key to hash
$table->rows;                      // array<string, array<string, mixed>>

$table->rowKeys();                 // list<string>
$table->rowCount();                // int
$table->hasRow('42');              // bool
$table->getRow('42');              // ?array<string, mixed>
$table->getFingerprint('42');      // ?string
```

---

## TableSnapshotData

`Merql\Snapshot\TableSnapshotData` is input data for `Snapshotter::fromData()`.

```php
use Merql\Snapshot\TableSnapshotData;
use Merql\Schema\TableSchema;

$data = new TableSnapshotData(
    schema: new TableSchema('posts', ['id' => 'int', 'title' => 'varchar'], ['id']),
    rows: [
        ['id' => '1', 'title' => 'First Post'],
    ],
    identityColumns: ['id'],
);
```

---

## IdentityRule

`Merql\Identity\IdentityRule` defines the columns used to build row keys.

```php
use Merql\Identity\IdentityRule;

$primary = IdentityRule::primary(['id']);
$natural = IdentityRule::natural(['option_name']);
$composite = IdentityRule::composite(['object_id', 'term_taxonomy_id']);
$content = IdentityRule::content(['name', 'value']);

$key = $natural->key(['option_name' => 'siteurl']);
// "siteurl"
```

### `forSchema(TableSchema $schema): IdentityRule` (static)

Resolve the default identity rule from schema: primary key, first unique key,
then content fallback.

### `toArray(): array`

Serialize the rule as `['type' => string, 'columns' => list<string>]`.

### `fromArray(array $data): IdentityRule` (static)

Hydrate a rule from serialized data.

---

## IdentityRuleSet

`Merql\Identity\IdentityRuleSet` stores table-specific identity overrides.

```php
use Merql\Identity\IdentityRule;
use Merql\Identity\IdentityRuleSet;

$rules = new IdentityRuleSet([
    'wp_options' => IdentityRule::natural(['option_name']),
]);

$rule = $rules->ruleFor($schema);
```

### `with(string $table, IdentityRule $rule): IdentityRuleSet`

Return a new rule set with one table override added.

### `conflictsFor(string $table, IdentityRule $rule, array $rows): array`

Return `IdentityConflict` entries when multiple rows produce the same key for a
rule.

### `toArray(): array`

Serialize table rules as a JSON-safe array.

### `fromArray(array $data): IdentityRuleSet` (static)

Hydrate a rule set from serialized data.

---

## SnapshotStore

`Merql\Snapshot\SnapshotStore` persists and loads snapshots as JSON files.

### `setDirectory(string $directory): void`

Set the storage directory. Default is `.merql/snapshots`.

### `save(Snapshot $snapshot): void`

Write a snapshot to disk.

### `load(string $name): Snapshot`

Load a snapshot by name. Throws `SnapshotException` if not found.

### `exists(string $name): bool`

Check whether a snapshot file exists.

### `delete(string $name): void`

Remove a snapshot file.

---

## Differ

`Merql\Diff\Differ` compares two snapshots and produces a changeset.

### `diff(Snapshot $base, Snapshot $current): Changeset`

```php
use Merql\Diff\Differ;

$differ = new Differ();
$changeset = $differ->diff($baseSnapshot, $currentSnapshot);
```

### `valuesEqual(mixed $a, mixed $b): bool` (static)

Compare two values using string coercion. NULL is treated as a distinct value: `NULL === NULL` is true, `NULL === ''` is false.

---

## Changeset

`Merql\Diff\Changeset` is a readonly collection of row-level operations.

```php
$changeset->inserts();             // list<RowInsert>
$changeset->updates();             // list<RowUpdate>
$changeset->deletes();             // list<RowDelete>
$changeset->isEmpty();             // bool
$changeset->count();               // int: total operations
$changeset->forTable('posts');     // Changeset: filtered to one table
```

---

## RowInsert, RowUpdate, RowDelete

Readonly value objects representing individual row changes.

### RowInsert

```php
$insert->table;                    // string
$insert->rowKey;                   // string
$insert->values;                   // array<string, mixed>
```

### RowUpdate

```php
$update->table;                    // string
$update->rowKey;                   // string
$update->columnDiffs;              // list<ColumnDiff>
$update->fullRow;                  // array<string, mixed>: complete current row
$update->columnDiffMap();          // array<string, ColumnDiff>: keyed by column name
```

### RowDelete

```php
$delete->table;                    // string
$delete->rowKey;                   // string
$delete->oldValues;                // array<string, mixed>: values before deletion
```

---

## ColumnDiff

`Merql\Diff\ColumnDiff` represents a single column change within a row update.

```php
$diff->column;                     // string
$diff->oldValue;                   // mixed
$diff->newValue;                   // mixed
```

---

## ThreeWayMerge

`Merql\Merge\ThreeWayMerge` is the core merge algorithm.

### `__construct(?CellMergeConfig $cellMergeConfig = null)`

Pass a `CellMergeConfig` to enable cell-level merging for TEXT and JSON columns.

```php
use Merql\Merge\ThreeWayMerge;
use Merql\CellMerge\CellMergeConfig;

// Default: column-level merge only.
$merge = new ThreeWayMerge();

// With cell-level merge for TEXT and JSON columns.
$merge = new ThreeWayMerge(CellMergeConfig::auto());
```

### `merge(Snapshot $base, Snapshot $ours, Snapshot $theirs): MergeResult`

Perform a three-way merge. Computes changesets internally (base to ours, base to theirs) and merges them.

```php
$result = $merge->merge($baseSnapshot, $oursSnapshot, $theirsSnapshot);
```

### `patch(Snapshot $base, Snapshot $changes): MergeResult`

Two-way merge shortcut. Equivalent to `merge($base, $base, $changes)`.

### `schemaMismatches(): array`

Returns `SchemaException[]` for any schema differences detected during the last merge (columns added or removed between snapshots).

---

## MergeResult

`Merql\Merge\MergeResult` is a readonly value object containing the merge outcome.

```php
$result->isClean();                // bool: no conflicts
$result->operations();             // list<MergeOperation>
$result->conflicts();              // list<Conflict>
$result->operationCount();         // int
$result->conflictCount();          // int
$result->baseSnapshot();           // ?Snapshot
$result->schemaMismatches();       // list<SchemaException>
$result->hasSchemaMismatches();    // bool
```

---

## MergePlan

`Merql\Plan\MergePlan` is a serializable review model for a merge.

```php
$plan->id;                        // string
$plan->baseSnapshot;              // string
$plan->oursSnapshot;              // string
$plan->theirsSnapshot;            // string
$plan->summary;                   // MergePlanSummary
$plan->changeGroups;              // list<MergePlanChangeGroup>
$plan->operations;                // list<MergePlanOperation>
$plan->conflicts;                 // list<MergePlanConflict>
$plan->schemaMismatches;          // list<string>
$plan->hash;                      // string
$plan->metadata;                  // array<string, mixed>
```

Use `MergePlanSerializer::toJson()` and `::fromJson()` for stable JSON
roundtrips.

## Selections

`ChangeGroupSelection` stores staged change group IDs. Convert it to an
`OperationSelection` when building selected merge results or rollback plans.

```php
use Merql\Plan\ChangeGroupSelection;
use Merql\Plan\SelectedMergeResultFactory;

$selection = ChangeGroupSelection::fromIds([$groupId]);
$operationSelection = $selection->toOperationSelection($plan);

$selected = (new SelectedMergeResultFactory())
    ->fromOperationSelection($plan, $operationSelection);
```

## RollbackPlan

`Merql\Rollback\RollbackPlan` stores inverse operations and drift expectations
for a selected apply.

```php
use Merql\Rollback\RollbackPlanBuilder;
use Merql\Rollback\RollbackPlanSerializer;

$rollback = (new RollbackPlanBuilder())->build(
    'rollback-1',
    $plan,
    $operationSelection,
    $liveBeforeRows,
);

$json = RollbackPlanSerializer::toJson($rollback);
```

Use `RollbackDrift` before applying rollback, then `RollbackPlanApplier` to run
the inverse operations.

---

## MergeOperation

`Merql\Merge\MergeOperation` is a single resolved operation from the merge.

```php
$op->type;                         // string: 'insert' | 'update' | 'delete'
$op->table;                        // string
$op->rowKey;                       // string
$op->values;                       // array<string, mixed>
$op->source;                       // string: 'ours' | 'theirs' | 'merged'
```

Type constants:

```php
MergeOperation::TYPE_INSERT;       // 'insert'
MergeOperation::TYPE_UPDATE;       // 'update'
MergeOperation::TYPE_DELETE;       // 'delete'
```

---

## Conflict

`Merql\Merge\Conflict` represents an unresolved merge conflict.

```php
$conflict->table();                // string
$conflict->rowKey();               // string
$conflict->type();                 // string: 'update_update' | 'update_delete' | 'delete_update' | 'insert_insert'
$conflict->column();               // ?string (null for structural conflicts)
$conflict->oursValue();            // mixed
$conflict->theirsValue();          // mixed
$conflict->baseValue();            // mixed
$conflict->primaryKey(['id']);     // array<string, string>: decoded from rowKey
```

### Conflict types

| Type | Meaning |
|---|---|
| `update_update` | Both sides changed the same column to different values |
| `update_delete` | Ours updated a row that theirs deleted |
| `delete_update` | Ours deleted a row that theirs updated |
| `insert_insert` | Both sides inserted a row with the same primary key |

---

## ConflictResolver

`Merql\Merge\ConflictResolver` applies a resolution policy to all conflicts in a merge result.

### `resolve(MergeResult $result, ConflictPolicy $policy): MergeResult` (static)

Returns a new `MergeResult` with conflicts resolved according to the policy.

```php
use Merql\Merge\ConflictResolver;
use Merql\Merge\ConflictPolicy;

// Accept all "theirs" values when conflicts arise.
$resolved = ConflictResolver::resolve($result, ConflictPolicy::TheirsWins);

// Accept all "ours" values.
$resolved = ConflictResolver::resolve($result, ConflictPolicy::OursWins);

// Return unchanged (for manual inspection).
$resolved = ConflictResolver::resolve($result, ConflictPolicy::Manual);
```

---

## ConflictPolicy

`Merql\Merge\ConflictPolicy` is a backed enum with three cases.

```php
use Merql\Merge\ConflictPolicy;

ConflictPolicy::OursWins;          // Resolve all conflicts in favor of "ours"
ConflictPolicy::TheirsWins;        // Resolve all conflicts in favor of "theirs"
ConflictPolicy::Manual;            // Leave conflicts unresolved for manual handling
```

---

## ColumnMerge

`Merql\Merge\ColumnMerge` performs per-column merge logic for a single row. Used internally by `ThreeWayMerge`.

### `merge(string $table, string $rowKey, array $base, array $ours, array $theirs, ?CellMergeConfig $cellMergeConfig = null, array $columnTypes = []): array` (static)

Returns `['values' => array, 'conflicts' => list<Conflict>]`.

```php
use Merql\Merge\ColumnMerge;

$result = ColumnMerge::merge(
    'posts',
    '42',
    ['title' => 'Hello', 'status' => 'draft'],     // base
    ['title' => 'Hello', 'status' => 'published'],  // ours
    ['title' => 'New Title', 'status' => 'draft'],   // theirs
);

// $result['values'] = ['title' => 'New Title', 'status' => 'published']
// $result['conflicts'] = [] (clean merge)
```

---

## Applier

`Merql\Apply\Applier` executes a merge result against a database.

### `__construct(DatabaseConnection $connection, ?Driver $driver = null)`

### `apply(MergeResult $result, ?Snapshot $base = null): ApplyResult`

Executes all operations in a single transaction. Rolls back on any error. Throws `ConflictException` if the result has unresolved conflicts.

```php
use Merql\Apply\Applier;

$applier = new Applier($connection);
$applied = $applier->apply($result);

echo "Rows affected: {$applied->rowsAffected()}\n";
if ($applied->hasErrors()) {
    print_r($applied->errors());
}
```

Foreign key ordering is handled automatically: inserts are sorted in dependency order, deletes in reverse dependency order.

---

## ApplyResult

`Merql\Apply\ApplyResult` is a readonly value object returned by the applier.

```php
$applied->rowsAffected();         // int
$applied->errors();               // list<string>
$applied->hasErrors();            // bool
```

---

## DryRun

`Merql\Apply\DryRun` generates human-readable SQL without executing anything.

### `generate(MergeResult $result, ?Snapshot $base = null, array $fkDependencies = [], ?Driver $driver = null): array` (static)

Returns a list of SQL strings with values inlined (for display purposes only).

```php
use Merql\Apply\DryRun;

$statements = DryRun::generate($result);

foreach ($statements as $sql) {
    echo "{$sql};\n";
}

// Output:
// INSERT INTO `posts` (`id`, `title`) VALUES ('10', 'New Post');
// UPDATE `posts` SET `title` = 'Updated' WHERE `id` = '5';
// DELETE FROM `comments` WHERE `id` = '3';
```

---

## SqlGenerator

`Merql\Apply\SqlGenerator` generates parameterized SQL statements. Used internally by both `Applier` and `DryRun`.

### `generate(MergeResult $result, ?Snapshot $base = null, array $fkDependencies = [], ?Driver $driver = null): array` (static)

Returns `list<array{sql: string, params: list<scalar|null>}>`.

```php
use Merql\Apply\SqlGenerator;

$statements = SqlGenerator::generate($result);

foreach ($statements as $stmt) {
    $connection->execute($stmt['sql'], $stmt['params']);
}
```

---

## CellMergeConfig

`Merql\CellMerge\CellMergeConfig` configures cell-level merge strategies for specific columns or column types.

### `auto(): self` (static)

Convenience factory that assigns `TextCellMerger` to TEXT/LONGTEXT columns and `JsonCellMerger` to JSON columns.

```php
use Merql\CellMerge\CellMergeConfig;

$config = CellMergeConfig::auto();
```

### `forColumn(string $column, CellMerger $merger): self`

Assign a merger to a specific column. Use `table.column` for qualified names or just `column` for all tables.

```php
use Merql\CellMerge\CellMergeConfig;
use Merql\CellMerge\TextCellMerger;

$config = (new CellMergeConfig())
    ->forColumn('posts.content', new TextCellMerger())
    ->forColumn('description', new TextCellMerger());
```

### `forType(string $typePattern, CellMerger $merger): self`

Assign a merger to all columns matching a type pattern (case-insensitive substring match).

```php
use Merql\CellMerge\CellMergeConfig;
use Merql\CellMerge\JsonCellMerger;

$config = (new CellMergeConfig())
    ->forType('json', new JsonCellMerger());
```

### `getMerger(string $table, string $column, string $columnType = ''): CellMerger`

Look up the merger for a given table, column, and type. Priority: qualified column name, unqualified column name, type pattern, default (opaque).

---

## CellMerger (interface)

`Merql\CellMerge\CellMerger` is the interface for cell-level merge strategies.

```php
use Merql\CellMerge\CellMerger;
use Merql\CellMerge\CellMergeResult;

interface CellMerger
{
    public function merge(mixed $base, mixed $ours, mixed $theirs): CellMergeResult;
}
```

Three built-in implementations are provided.

### TextCellMerger

`Merql\CellMerge\TextCellMerger` merges multi-line text using a three-way line-by-line diff (the same algorithm git uses for file content). When both sides edit different lines, the merge is clean. When both sides edit the same line differently, it reports a conflict.

```php
use Merql\CellMerge\TextCellMerger;

$merger = new TextCellMerger();
$result = $merger->merge(
    "line 1\nline 2\nline 3",
    "line 1\nline 2 modified\nline 3",
    "line 1\nline 2\nline 3 modified",
);
// $result->clean === true
// $result->value === "line 1\nline 2 modified\nline 3 modified"
```

### JsonCellMerger

`Merql\CellMerge\JsonCellMerger` merges JSON values by comparing top-level keys independently. Each key is merged using three-way logic. Nested objects are compared as opaque JSON strings (not recursively merged).

```php
use Merql\CellMerge\JsonCellMerger;

$merger = new JsonCellMerger();
$result = $merger->merge(
    '{"a": 1, "b": 2}',
    '{"a": 10, "b": 2}',        // changed "a"
    '{"a": 1, "b": 20}',         // changed "b"
);
// $result->clean === true
// $result->value === '{"a":10,"b":20}'
```

### OpaqueCellMerger

`Merql\CellMerge\OpaqueCellMerger` is the default. It treats values as opaque strings and always reports a conflict when they differ.

---

## CellMergeResult

`Merql\CellMerge\CellMergeResult` is the return type of all cell mergers.

```php
$result->clean;                    // bool: true if merge succeeded
$result->value;                    // mixed: merged value (or ours on conflict)
$result->conflicts;                // int: number of conflicts

// Factory methods.
CellMergeResult::resolved($value);           // clean merge
CellMergeResult::conflict($oursValue, $n);   // conflict with count
```

---

## TableSchema

`Merql\Schema\TableSchema` is a readonly representation of table structure.

```php
use Merql\Schema\TableSchema;

$schema = new TableSchema(
    name: 'posts',
    columns: ['id' => 'int', 'title' => 'varchar(255)', 'content' => 'text'],
    primaryKey: ['id'],
    uniqueKeys: [['slug']],
);

$schema->name;                     // string
$schema->columns;                  // array<string, string>: name to type
$schema->primaryKey;               // list<string>
$schema->uniqueKeys;               // list<list<string>>
$schema->hasPrimaryKey();          // bool
$schema->hasUniqueKeys();          // bool
$schema->columnNames();            // list<string>
```

---

## Driver system

merql uses a pluggable driver system to abstract database-specific operations. Drivers are auto-detected from the connection driver identity.

### Driver (interface)

`Merql\Driver\Driver` defines the contract all database drivers implement.

```php
interface Driver
{
    public function quoteIdentifier(string $name): string;
    public function listTables(DatabaseConnection $connection): array;
    public function readSchema(DatabaseConnection $connection, string $table): TableSchema;
    public function readForeignKeys(DatabaseConnection $connection): array;
    public function selectAll(string $table): string;
}
```

Built-in implementations: `MysqlDriver` and `SqliteDriver`.

### DriverFactory

`Merql\Driver\DriverFactory` auto-detects the driver from a connection.

```php
use Merql\Driver\DriverFactory;

$driver = DriverFactory::create($connection);
```

Register a custom driver for other databases:

```php
DriverFactory::register('pgsql', MyPostgresDriver::class);
```

---

## Filters

### TableFilter

`Merql\Filter\TableFilter` includes or excludes tables using glob patterns.

```php
use Merql\Filter\TableFilter;

$filter = TableFilter::exclude(['cache_*', 'sessions', 'temp_*']);
$filter = TableFilter::include(['posts', 'users', 'comments']);
```

### ColumnFilter

`Merql\Filter\ColumnFilter` removes specific columns from snapshots.

```php
use Merql\Filter\ColumnFilter;

$filter = ColumnFilter::ignore(['updated_at', 'created_at', 'modified_date']);
```

### RowFilter

`Merql\Filter\RowFilter` filters rows using a predicate function.

```php
use Merql\Filter\RowFilter;

$filter = RowFilter::create(function (string $table, array $row): bool {
    // Exclude trashed posts.
    if ($table === 'posts' && ($row['status'] ?? '') === 'trash') {
        return false;
    }
    return true;
});
```

---

## Exceptions

### SnapshotException

`Merql\Exceptions\SnapshotException` is thrown when a snapshot cannot be found or has an invalid name.

```php
SnapshotException::notFound('baseline', '/path/to/baseline.json');
```

### MergeException

`Merql\Exceptions\MergeException` is thrown when a required snapshot is missing for a merge.

```php
MergeException::snapshotRequired('base');
```

### ConflictException

`Merql\Exceptions\ConflictException` is thrown when attempting to apply a merge result that has unresolved conflicts.

```php
ConflictException::unresolved(3);
// Message: "Cannot apply merge with 3 unresolved conflict(s)"
```

### SchemaException

`Merql\Exceptions\SchemaException` is thrown (or collected) when schemas differ between snapshots.

```php
SchemaException::mismatch('posts', 'column "tags" exists in current but not in base');
```

Schema mismatches do not stop the merge. They are collected and available via `$result->schemaMismatches()` for inspection.
