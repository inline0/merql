---
title: "Merge Plans"
description: "Build UI-ready merge plans with stable operation IDs, change groups, selections, and JSON serialization."
path: "merge/plans"
order: 40
section: "Merge"
meta_title: "Merge Plans"
meta_description: "Build UI-ready merge plans with stable operation IDs, change groups, selections, and JSON serialization."
---

# Merge Plans

A `MergeResult` is the executable output of a merge. A `MergePlan` is the
inspectable form of that output. Use it when an application needs to show,
stage, serialize, or selectively apply database changes before writing to the
target database.

```php
use Merql\Merql;

$plan = Merql::plan(
    id: 'plan-2026-06-06',
    base: 'base',
    ours: 'ours',
    theirs: 'theirs',
    metadata: ['sandbox_id' => 'ow_123'],
);
```

`Merql::plan()` loads the three named snapshots and merges them at the column
level, like `Merql::merge()`. To plan a merge that uses cell-level merge for
TEXT and JSON columns, build the result yourself and pass it to
`MergePlanBuilder`:

```php
use Merql\CellMerge\CellMergeConfig;
use Merql\Merge\ThreeWayMerge;
use Merql\Plan\MergePlanBuilder;
use Merql\Snapshot\SnapshotStore;

$base = SnapshotStore::load('base');
$ours = SnapshotStore::load('ours');
$theirs = SnapshotStore::load('theirs');

$result = (new ThreeWayMerge(CellMergeConfig::auto()))->merge($base, $ours, $theirs);
$plan = (new MergePlanBuilder())->build('plan-2026-06-06', $base, $ours, $theirs, $result);
```

The plan contains:

- the snapshot names used to build it,
- a summary of operations, conflicts, schema mismatches, tables, and groups,
- stable operation IDs,
- stable change group IDs,
- row identity columns and identity values,
- base, ours, theirs, and result row values for each operation,
- optional host application metadata,
- a plan hash,
- a schema version.

## Stable IDs

Operation IDs are deterministic for the same operation contents. They are
derived from table name, row key, operation type, operation source, changed
columns, and result values.

Change group IDs are deterministic for the same group contents. The default
builder creates one operation group per operation. Host applications can build
larger domain-specific groups from the operation list and still serialize them
with the same DTO shape.

```php
foreach ($plan->changeGroups as $group) {
    echo $group->id . ': ' . implode(', ', $group->operationIds);
}
```

## Operation Shape

Each `MergePlanOperation` keeps enough context for review and rollback.

```php
$operation = $plan->operations[0];

$operation->id;              // stable operation ID
$operation->type;            // insert, update, delete
$operation->table;           // canonical table name
$operation->rowKey;          // encoded identity key
$operation->identityColumns; // columns used for identity
$operation->identityValues;  // decoded identity values
$operation->source;          // ours, theirs, or merged
$operation->baseRow;         // row in base snapshot, or null
$operation->oursRow;         // row in ours snapshot, or null
$operation->theirsRow;       // row in theirs snapshot, or null
$operation->resultValues;    // values the merge would write
$operation->changedColumns;  // changed result columns
```

## Selections

Use `ChangeGroupSelection` when the UI stages groups. Convert it to an
`OperationSelection` when applying or generating rollback artifacts.

```php
use Merql\Plan\ChangeGroupSelection;
use Merql\Plan\SelectedMergeResultFactory;
use Merql\Snapshot\SnapshotStore;

$selection = ChangeGroupSelection::fromIds([
    $plan->changeGroups[0]->id,
]);

$operationSelection = $selection->toOperationSelection($plan);

$selectedResult = (new SelectedMergeResultFactory())->fromOperationSelection(
    $plan,
    $operationSelection,
    SnapshotStore::load($plan->baseSnapshot),
);
```

The selected result is a clean `MergeResult` that contains only selected
operations. It can be passed to `Merql::apply()` or `Merql::applyGuarded()`.

## Selecting What A Target Is Missing

A plan holds the operations of both sides, because the merge result turns base
into the merged state. When the target already holds one side, select only the
operations it does not have yet. For a target that holds theirs, such as a live
database receiving the edits of a sandbox copy, that is every operation with
source `ours`, plus `merged` updates, which combine both sides' column changes:

```php
use Merql\Plan\OperationSelection;
use Merql\Plan\SelectedMergeResultFactory;
use Merql\Snapshot\SnapshotStore;

// Rows with an unresolved conflict stay out of the selection.
$conflicted = [];
foreach ($plan->conflicts as $conflict) {
    $conflicted[$conflict->table . "\0" . $conflict->rowKey] = true;
}

$ids = [];
foreach ($plan->operations as $operation) {
    $missing = $operation->source === 'ours'
        || ($operation->source === 'merged' && $operation->type === 'update');

    if ($missing && !isset($conflicted[$operation->table . "\0" . $operation->rowKey])) {
        $ids[] = $operation->id;
    }
}

$selected = (new SelectedMergeResultFactory())->fromOperationSelection(
    $plan,
    OperationSelection::fromIds($ids),
    SnapshotStore::load($plan->baseSnapshot),
);
```

Operations with source `theirs`, and `merged` deletes (rows both sides
deleted), are already present in that target.

A plan built from a merge with conflicts still lists an operation for each
conflicted row that both sides updated. Its result values hold ours' value in
the conflicting column, and `SelectedMergeResultFactory` returns a clean result
for any selection. The operation's and change group's `conflictState` currently
read `clean` even then ([#9](https://github.com/inline0/merql/issues/9)), so
check `$plan->conflicts` yourself as above, or resolve conflicts with
`ConflictResolver` and build the plan from the resolved result with
`MergePlanBuilder`. See
[Guarded Apply](/docs/apply/guarded) for writing the selection with live-row
preconditions.

## Serialization

Merge plans and selections have stable JSON serialization. Store the serialized
plan when a later apply step must use the exact reviewed operation set.

```php
use Merql\Plan\ChangeGroupSelection;
use Merql\Plan\MergePlanSerializer;

$json = MergePlanSerializer::toJson($plan);
$restored = MergePlanSerializer::fromJson($json);

$selectionJson = json_encode($selection->toArray(), JSON_THROW_ON_ERROR);
$selection = ChangeGroupSelection::fromArray(
    json_decode($selectionJson, true, flags: JSON_THROW_ON_ERROR),
);
```

## Aliased Snapshots

Merge plans use canonical table names. If physical table names differ between
environments, capture them under the same canonical name before planning.

```php
use Merql\Snapshot\SnapshotStore;
use Merql\Snapshot\Snapshotter;

$snapshotter = new Snapshotter($connection);

SnapshotStore::save($snapshotter->captureAliased('live', [
    'wp_posts' => 'wp_posts',
]));

SnapshotStore::save($snapshotter->captureAliased('sandbox', [
    'wp_onumia_abc_posts' => 'wp_posts',
]));
```

The schema stored in the snapshot uses the canonical table name while preserving
the physical table's columns, keys, identity columns, row keys, and filters.
