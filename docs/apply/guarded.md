---
title: "Guarded Apply"
description: "Apply merge results with optimistic live-row preconditions so stale plans abort before partial writes."
path: "apply/guarded"
order: 90
section: "Apply"
meta_title: "Guarded Apply"
meta_description: "Apply merge results with optimistic live-row preconditions so stale plans abort before partial writes."
---

# Guarded Apply

`GuardedApplier` applies a clean `MergeResult` only if the target database still
matches the expected live snapshot. This is useful when a merge plan was
reviewed earlier and live rows may have changed before apply.

The typical setup promotes the edits of a sandbox into a live database. Base is
live at the moment the sandbox was copied from it, ours is the edited sandbox,
and theirs is live as it is now. The target is the live database, so the
expected live snapshot is `theirs`, and the operations to write are the ones
live does not have yet (see [Choosing operations](#choosing-operations)).

```php
use Merql\Connection;
use Merql\Merql;
use Merql\Plan\OperationSelection;
use Merql\Plan\SelectedMergeResultFactory;
use Merql\Snapshot\SnapshotStore;

Merql::init(Connection::sqlite('/data/live.sqlite'));

$plan = Merql::plan('promote-1', 'base', 'ours', 'theirs');

// Skip rows with unresolved conflicts.
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
    SnapshotStore::load('base'),
);

$applied = Merql::applyGuarded($selected, SnapshotStore::load('theirs'));

if ($applied->hasErrors()) {
    foreach ($applied->errors() as $error) {
        echo $error . "\n";
    }
}
```

`Merql::applyGuarded()` writes to the database passed to `Merql::init()`. The
expected live snapshot must describe that same database: take it from the
target, and take it recently enough that the preconditions mean something.

## Preconditions

Guarded SQL adds live-row preconditions to generated statements:

- updates require the target row identity to exist and non-identity column
  values to match the expected live row,
- deletes require the target row identity to exist and non-identity column
  values to match the expected live row,
- inserts require that the target identity does not already exist.

If any statement affects zero rows, merql treats the plan as stale. The
transaction is rolled back and the returned `ApplyResult` contains the error
`Optimistic precondition failed for guarded merge statement.`

On MySQL, an update whose values already equal the live row also affects zero
rows, because MySQL counts changed rows rather than matched rows. Such an
update fails as stale even though the row matches ([#2](https://github.com/inline0/merql/issues/2)).
SQLite counts matched rows and accepts it. This can happen with a `merged`
update when both sides made the same change to a row.

## Choosing operations

A merge result holds the operations of both sides, since it turns base into the
merged state. Applied to a target that already holds theirs, the operations
that came from theirs describe changes the target already has, and their
preconditions fail:

| Operation | State in a target that holds theirs | Select |
|---|---|---|
| `ours` insert, update, or delete | Missing | Yes |
| `merged` update (both sides changed the row) | Has theirs' columns, missing ours' | Yes |
| `theirs` insert | Row exists, so the guarded insert affects zero rows | No |
| `merged` delete (both sides deleted the row) | Row is gone, so the guarded delete affects zero rows | No |
| `theirs` update or delete | Already applied | No |

Passing the whole result of `Merql::merge()` to `applyGuarded()` therefore fails
as soon as theirs inserted or both sides deleted a row. Select the operations
first, by source as above or with a `ChangeGroupSelection` built from a review
UI. Leave out rows listed in `$plan->conflicts`: a selected result is always
clean, and the operation for a conflicted row carries ours' value in the
conflicting column (see [Merge Plans](/docs/merge/plans#selecting-what-a-target-is-missing)).

## Selected Apply

Guarded apply works with selected merge results produced from a merge plan,
including selections made by change group.

```php
use Merql\Plan\ChangeGroupSelection;
use Merql\Plan\SelectedMergeResultFactory;
use Merql\Snapshot\SnapshotStore;

$selection = ChangeGroupSelection::fromIds([$plan->changeGroups[0]->id]);
$selected = (new SelectedMergeResultFactory())->fromChangeGroupSelection(
    $plan,
    $selection,
    SnapshotStore::load($plan->baseSnapshot),
);

$applied = Merql::applyGuarded($selected, SnapshotStore::load($plan->theirsSnapshot));
```

This lets a host application stage a subset of reviewed groups and still assert
that live rows did not drift after plan creation.

## SQL Preview

Use `SqlGenerator::generateGuarded()` to inspect the guarded statements without
executing them.

```php
use Merql\Apply\SqlGenerator;
use Merql\Snapshot\SnapshotStore;

$statements = SqlGenerator::generateGuarded(
    $selected,
    SnapshotStore::load($plan->theirsSnapshot),
);

foreach ($statements as $statement) {
    echo $statement['sql'] . "\n";
}
```

The generated statements are still parameterized. Preconditions are expressed
with additional `WHERE` clauses for updates/deletes and `NOT EXISTS` checks for
inserts.

## Guarded or plain apply

`Merql::apply()` runs the same operations without preconditions. Updates and
deletes then succeed whether or not the live row changed since the plan was
built, and an insert of an existing row fails with the database's duplicate key
error. Use plain apply for a target you know still holds base, and guarded
apply when other writers may have touched the target since it was snapshotted.
