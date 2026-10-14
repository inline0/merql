---
title: "Getting Started"
description: "Install merql, capture your first snapshot, compute a diff, and perform a three-way merge."
path: "getting-started"
order: 20
meta_title: "Getting Started"
meta_description: "Install merql, capture your first snapshot, compute a diff, and perform a three-way merge."
---

# Getting Started

This guide walks through installation, connecting to a database, capturing snapshots, computing diffs, and performing a three-way merge.

## Installation

```bash
composer require merql/merql
```

merql requires PHP 8.2+ with `ext-mbstring` and `ext-zlib` (both required by
the `pitmaster/pitmaster` dependency). You also need the extension for the
adapter you use: `ext-pdo` with `ext-pdo_sqlite` or `ext-pdo_mysql` for the
PDO adapter, or `ext-mysqli` for the mysqli adapter. Composer only suggests
these, so check that the one you need is installed.

The package installs the CLI as `vendor/bin/merql`. Use merql 0.4.1 or later
for the CLI; in earlier releases the binary could not find the autoloader when
merql was installed as a dependency.

## Connecting to a database

merql works through a small connection adapter interface. Built-in adapters are
provided for PDO and mysqli, with built-in dialect drivers for MySQL and SQLite.

```php
use Merql\Connection;

// SQLite (in-memory or file-based, through PDO).
$connection = Connection::sqlite(':memory:');
$connection = Connection::sqlite('/path/to/database.sqlite');

// MySQL through PDO.
$connection = Connection::mysql('127.0.0.1', 'my_database', 'root', 'password');

// MySQL through mysqli.
$connection = Connection::mysqli('127.0.0.1', 'my_database', 'root', 'password');

// Any MySQL or SQLite PDO DSN.
$connection = Connection::fromDsn('mysql:host=127.0.0.1;dbname=my_database;charset=utf8mb4', 'root', 'password');
```

`fromDsn()` opens any DSN PDO supports, but merql only ships dialect drivers
for MySQL and SQLite. With another engine, such as `pgsql:`, the connection
opens but `Merql::init()` throws `Unsupported database driver: pgsql` until you
register a driver for it. See [Database Drivers](/docs/apply/drivers).

## Capturing a snapshot

A snapshot records every row of every table (or a subset of tables) as fingerprints and data. Fingerprints enable fast change detection; full row data is available when a diff is needed.

```php
use Merql\Merql;

Merql::init($connection);

// Snapshot all tables.
$snapshot = Merql::snapshot('baseline');

// Snapshot specific tables.
$snapshot = Merql::snapshot('baseline', ['posts', 'comments', 'users']);
```

Snapshots are persisted as JSON files in `.merql/snapshots/` by default. You can change this location:

```php
use Merql\Snapshot\SnapshotStore;

SnapshotStore::setDirectory('/var/data/merql-snapshots');
```

## Computing a diff

A diff compares two snapshots and returns a changeset of inserts, updates, and deletes. Both snapshots must already exist; here `current` is a second snapshot taken after the database changed.

```php
$changeset = Merql::diff('baseline', 'current');

echo "Inserts: " . count($changeset->inserts()) . "\n";
echo "Updates: " . count($changeset->updates()) . "\n";
echo "Deletes: " . count($changeset->deletes()) . "\n";

// Inspect changes for a single table.
$postChanges = $changeset->forTable('posts');

// Check per-column diffs on updates.
foreach ($changeset->updates() as $update) {
    echo "Row {$update->rowKey} in {$update->table}:\n";
    foreach ($update->columnDiffs as $diff) {
        echo "  {$diff->column}: {$diff->oldValue} -> {$diff->newValue}\n";
    }
}
```

## Three-way merge

The core operation. Takes three named snapshots (base, ours, theirs) and merges the two sets of changes.

Base is the state both copies started from. Ours and theirs are the two copies after they changed independently, so each must be captured from the database that holds it. For example, when a sandbox was copied from a live database:

```php
use Merql\Connection;
use Merql\Merql;

$live    = Connection::sqlite('/data/live.sqlite');
$sandbox = Connection::sqlite('/data/sandbox.sqlite');

Merql::init($live);
Merql::snapshot('base');      // before the sandbox is copied from live

// ... copy live.sqlite to sandbox.sqlite, then both databases change ...

Merql::init($sandbox);
Merql::snapshot('ours');      // the sandbox with our edits
Merql::init($live);
Merql::snapshot('theirs');    // live with its own changes

$result = Merql::merge('base', 'ours', 'theirs');

echo "Operations: {$result->operationCount()}\n";
echo "Conflicts:  {$result->conflictCount()}\n";
echo "Clean:      " . ($result->isClean() ? 'yes' : 'no') . "\n";
```

Do not take all three snapshots from one database in sequence. Theirs would then already contain ours' changes, and every row ours inserted would show up as an `insert_insert` conflict.

`Merql::merge()` merges at the column level. To also merge inside TEXT and JSON values, use `ThreeWayMerge` with a `CellMergeConfig`; see [Cell-Level Merge](/docs/merge/cell-level).

### Handling conflicts

When both sides change the same column to different values, merql raises a conflict. You can resolve conflicts automatically or inspect them manually.

```php
use Merql\Merge\ConflictPolicy;
use Merql\Merge\ConflictResolver;

if (!$result->isClean()) {
    // Auto-resolve: one side wins.
    $resolved = ConflictResolver::resolve($result, ConflictPolicy::TheirsWins);

    // Or inspect each conflict.
    foreach ($result->conflicts() as $conflict) {
        echo "Conflict on {$conflict->table()} row {$conflict->rowKey()}:\n";
        echo "  Type:   {$conflict->type()}\n";
        echo "  Column: {$conflict->column()}\n";
        echo "  Ours:   " . var_export($conflict->oursValue(), true) . "\n";
        echo "  Theirs: " . var_export($conflict->theirsValue(), true) . "\n";
    }
}
```

## Applying the merge

Once you have a clean merge result (no unresolved conflicts), apply it to the database passed to `Merql::init()`.

The operations in a merge result turn base into the merged state, and they include the changes of both sides. `Merql::apply()` executes every one of them, so it applies cleanly to a database that still holds the base state. Applied to a database that already contains one side's changes, any insert from that side hits a row that already exists, fails, and rolls back the whole merge. To promote one side's changes into a database that already holds the other side (sandbox edits into live, for example), apply only the operations it is missing with [Guarded Apply](/docs/apply/guarded).

```php
$applied = Merql::apply($result);

echo "Rows affected: {$applied->rowsAffected()}\n";

if ($applied->hasErrors()) {
    foreach ($applied->errors() as $error) {
        echo "Error: {$error}\n";
    }
}
```

The applier wraps all operations in a transaction. If any statement fails, the entire merge is rolled back, `rowsAffected()` returns 0, and the error is returned in `errors()`. Check `hasErrors()` before treating an apply as successful.

## Dry run

Preview the SQL that a merge would generate without executing it.

```php
use Merql\Apply\DryRun;

$statements = DryRun::generate($result);

foreach ($statements as $sql) {
    echo "{$sql};\n";
}
```

## Two-way patch

When you only have a base and a set of changes (no concurrent "ours" modifications), use `patch()`. This is equivalent to a merge where ours is unchanged from base.

```php
$result = Merql::patch('base', 'changes');
// Always clean: no concurrent edits means no conflicts.
Merql::apply($result);
```

## Using the CLI

All of these operations are also available from the command line. See the [CLI Reference](/docs/cli) for full details.

```bash
export MERQL_DB_DSN="sqlite:mydb.sqlite"

vendor/bin/merql snapshot base
# ... change the database ...
vendor/bin/merql snapshot current
vendor/bin/merql diff base current
```

For a three-way merge, take each snapshot from the database that holds that state, then merge:

```bash
MERQL_DB_DSN="sqlite:/data/live.sqlite" vendor/bin/merql snapshot base
MERQL_DB_DSN="sqlite:/data/sandbox.sqlite" vendor/bin/merql snapshot ours
MERQL_DB_DSN="sqlite:/data/live.sqlite" vendor/bin/merql snapshot theirs
vendor/bin/merql merge base ours theirs --dry-run
```

## Filtering tables and columns

Snapshots can exclude tables (like cache or session tables) and ignore volatile columns (like `updated_at` timestamps).

```php
use Merql\Filter\TableFilter;
use Merql\Filter\ColumnFilter;
use Merql\Snapshot\Snapshotter;

$snapshotter = new Snapshotter($connection);

$snapshot = $snapshotter->capture('baseline', filters: [
    TableFilter::exclude(['sessions', 'cache_*']),
    ColumnFilter::ignore(['updated_at', 'modified_date']),
]);
```

## Next steps

- [CLI Reference](/docs/cli) for the full command-line interface.
- [PHP API Reference](/docs/api) for detailed documentation of every class and method.
