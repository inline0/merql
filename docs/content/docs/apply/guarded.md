---
title: "Guarded Apply"
description: "Apply merge results with optimistic live-row preconditions so stale plans abort before partial writes."
path: "apply/guarded"
order: 10
section: "Apply"
meta_title: "Guarded Apply"
meta_description: "Apply merge results with optimistic live-row preconditions so stale plans abort before partial writes."
---

# Guarded Apply

`GuardedApplier` applies a clean `MergeResult` only if the target database still
matches the expected live snapshot. This is useful when a merge plan was
reviewed earlier and live rows may have changed before apply.

```php
use Merql\Merql;
use Merql\Snapshot\SnapshotStore;

$result = Merql::merge('base', 'ours', 'theirs');
$expectedLive = SnapshotStore::load('theirs');

$applied = Merql::applyGuarded($result, $expectedLive);

if ($applied->hasErrors()) {
    foreach ($applied->errors() as $error) {
        echo $error . "\n";
    }
}
```

## Preconditions

Guarded SQL adds live-row preconditions to generated statements:

- updates require the target row identity to exist and non-identity column
  values to match the expected live row,
- deletes require the target row identity to exist and non-identity column
  values to match the expected live row,
- inserts require that the target identity does not already exist.

If any statement affects zero rows, MerQL treats the plan as stale. The
transaction is rolled back and the returned `ApplyResult` contains the error.

## Selected Apply

Guarded apply works with selected merge results produced from a merge plan.

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
