---
title: "Three-Way Merge"
description: "How merql computes changesets from three database states and merges them using git-style rules."
path: "merge/three-way"
order: 30
section: "Merge"
meta_title: "Three-Way Merge"
meta_description: "How merql computes changesets from three database states and merges them using git-style rules."
---

# Three-Way Merge

A three-way merge compares two sets of changes against a common ancestor and combines them into one result. merql applies this algorithm to database rows instead of file lines.

```php
use Merql\Merge\ThreeWayMerge;

$merge = new ThreeWayMerge();
$result = $merge->merge($base, $ours, $theirs);

echo $result->operationCount(); // resolved operations
echo $result->conflictCount();  // unresolved conflicts
```

## The three states

Every merge requires exactly three snapshots:

- **Base** -- the database state at the point both branches diverged.
- **Ours** -- one branch after its changes, for example a sandbox copy that was edited.
- **Theirs** -- the other branch after its changes, for example the live database that kept running.

Ours and theirs must each be captured from the database that holds that branch. The merge rules treat both sides alike; the names decide each operation's `source`, how conflict types are named (`update_delete` means ours updated and theirs deleted), and which side `ConflictPolicy::OursWins` and `TheirsWins` favour.

```
         Base (snapshot at fork time)
        /                              \
   Ours (our changes)                   Theirs (their changes)
        \                              /
         --------- MERGE ----------
                     |
              Merged result
              + conflicts (if any)
```

Without a common base, you cannot distinguish "added" from "unchanged." Two-way comparison sees that side A has a value and side B does not, but cannot determine whether A added it or B deleted it. The base answers that question.

## How changesets are computed

The `Differ` compares each snapshot against the base to produce two changesets. Each changeset contains three kinds of operations:

| Base | Current | Operation |
|---|---|---|
| Row exists | Row exists, same fingerprint | No change |
| Row exists | Row exists, different fingerprint | **UPDATE** (per-column diff) |
| Row exists | Row missing | **DELETE** |
| Row missing | Row exists | **INSERT** |

Fingerprints (SHA-256 hashes of normalized row content) provide a fast path. If two fingerprints match, the row is unchanged and no column-level comparison is needed.

```php
use Merql\Diff\Differ;

$differ = new Differ();
$oursChangeset = $differ->diff($base, $ours);
$theirsChangeset = $differ->diff($base, $theirs);
```

## Merge rules

The two changesets are then merged operation by operation. This table defines the outcome for every combination:

| Ours | Theirs | Result |
|---|---|---|
| No change | No change | Keep base |
| No change | Updated | Accept theirs |
| Updated | No change | Accept ours |
| Updated (same value) | Updated (same value) | Accept (both agree) |
| Updated (different value) | Updated (different value) | **Conflict** |
| No change | Deleted | Accept delete |
| Deleted | No change | Accept delete |
| Deleted | Deleted | Accept delete (both agree) |
| Updated | Deleted | **Conflict** |
| Deleted | Updated | **Conflict** |
| Inserted (ours only) | -- | Accept insert |
| -- | Inserted (theirs only) | Accept insert |
| Inserted (same PK) | Inserted (same PK) | **Conflict** |

When both sides update the same row, merql does not immediately declare a conflict. It drops to column-level merge, comparing each column independently. See [Column-Level Merge](/docs/merge/column-level) for details.

## Using the facade

The `Merql` facade operates on named snapshots that have been persisted to disk. Call `Merql::init()` with the database that holds each state before snapshotting it:

```php
use Merql\Connection;
use Merql\Merql;

$live    = Connection::sqlite('/data/live.sqlite');
$sandbox = Connection::sqlite('/data/sandbox.sqlite');

Merql::init($live);
Merql::snapshot('base');      // before the sandbox is copied from live

// ... copy live.sqlite to sandbox.sqlite, then both databases change ...

Merql::init($sandbox);
Merql::snapshot('ours');
Merql::init($live);
Merql::snapshot('theirs');

$result = Merql::merge('base', 'ours', 'theirs');
```

Do not take all three snapshots from one database in sequence. Theirs would then contain ours' changes as well, so every row ours inserted would appear as inserted on both sides and be reported as an `insert_insert` conflict.

`Merql::merge()`, `Merql::patch()`, and `Merql::plan()` accept an optional
`CellMergeConfig`, or use the default passed to `Merql::init()`. Without one,
they merge at the column level. You can also load snapshots and configure
`ThreeWayMerge` directly, as below.

## Using the classes directly

For more control, instantiate the components yourself. `Snapshotter::capture()` does not persist anything, so keep the snapshots in memory or save them with `SnapshotStore::save()`:

```php
use Merql\CellMerge\CellMergeConfig;
use Merql\Merge\ThreeWayMerge;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\SnapshotStore;

$base   = (new Snapshotter($live))->capture('base');
// ... copy live to the sandbox, then both databases change ...
$ours   = (new Snapshotter($sandbox))->capture('ours');
$theirs = (new Snapshotter($live))->capture('theirs');

$merge = new ThreeWayMerge();
$result = $merge->merge($base, $ours, $theirs);

// Snapshots saved earlier, merged with cell-level merge for TEXT and JSON columns.
$merge = new ThreeWayMerge(CellMergeConfig::auto());
$result = $merge->merge(
    SnapshotStore::load('base'),
    SnapshotStore::load('ours'),
    SnapshotStore::load('theirs'),
);
```

## The patch shortcut

When only one side has changes (no concurrent modifications), use `patch()`. This is a two-way merge where ours equals base, so conflicts are impossible:

```php
$result = $merge->patch($base, $changes);
// Always clean: ours is identical to base.
```

Through the facade:

```php
$result = Merql::patch('base', 'changes');
```

`patch()` is equivalent to `merge($base, $base, $changes)`. Since ours never diverged from base, every change from theirs is accepted without conflict.

## Schema mismatch detection

If columns were added to or removed from a table between snapshots, the merge still proceeds but records the mismatches. Changed column types are not compared, and a renamed column shows up as one removed and one added; see [Schema Validation](/docs/advanced/schema).

```php
$result = $merge->merge($base, $ours, $theirs);

if ($result->hasSchemaMismatches()) {
    foreach ($result->schemaMismatches() as $mismatch) {
        echo $mismatch->getMessage() . "\n";
    }
}
```

merql does not auto-migrate schemas. Schema changes are the caller's responsibility. The merge reports them so you can decide how to proceed.

## The MergeResult

The result object carries everything you need:

```php
$result->isClean();            // true if no conflicts
$result->operations();         // list of MergeOperation objects
$result->conflicts();          // list of Conflict objects
$result->operationCount();     // number of resolved operations
$result->conflictCount();      // number of unresolved conflicts
$result->hasSchemaMismatches(); // true if schemas differ
$result->baseSnapshot();       // the base snapshot used for the merge
```

Each `MergeOperation` has a type (`insert`, `update`, or `delete`), a table name, a row key, the column values, and a source (`ours`, `theirs`, or `merged`). `merged` marks a row both sides updated (combined column by column) or both sides deleted.

## Where a merge result applies

A merge result describes how to turn base into the merged state. Its operations carry the changes of both sides: an insert ours made and an insert theirs made both appear as inserts.

- `Merql::apply()` (or `Applier`) executes every operation against the connected database. It applies cleanly to a database that still holds base, such as a fresh copy of the base state. Against a database that already contains one side's changes, an insert of a row that exists fails and the transaction is rolled back.
- To bring ours into a database that already holds theirs, such as promoting sandbox edits into live, apply only the operations that database is missing: those with source `ours`, and `merged` updates. Use a [merge plan](/docs/merge/plans) to select them and [Guarded Apply](/docs/apply/guarded) to write them with live-row preconditions.
