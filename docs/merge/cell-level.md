---
title: "Cell-Level Merge"
description: "Merge within individual cells using text line-by-line diff and JSON key-by-key merge."
path: "merge/cell-level"
order: 60
section: "Merge"
meta_title: "Cell-Level Merge"
meta_description: "Merge within individual cells using text line-by-line diff and JSON key-by-key merge."
---

# Cell-Level Merge

Column-level merge detects when both sides changed the same column. Cell-level merge goes deeper, merging the content inside that column. Two people editing different lines of a TEXT field, or different keys of a JSON object, can merge cleanly.

```php
use Merql\CellMerge\CellMergeConfig;
use Merql\Merge\ThreeWayMerge;

$config = CellMergeConfig::auto();
$merge = new ThreeWayMerge($config);
$result = $merge->merge($base, $ours, $theirs);
```

## Enabling cell-level merge

Cell-level merge is opt-in. It runs when a `CellMergeConfig` is passed to
`ThreeWayMerge`, the facade, or selected with the CLI flag. Without one, every column that both sides changed to different values is an `update_update` conflict.

Pass `cellMergeConfig: CellMergeConfig::auto()` to `Merql::init()` to use it
for subsequent `Merql::merge()`, `Merql::patch()`, and `Merql::plan()` calls.
Each method also accepts an optional `cellMergeConfig` argument for a single
call. `Merql::reset()` clears the configured default. The CLI exposes the same
automatic TEXT and JSON strategies with `merql merge --cell-merge`.

To merge snapshots saved by the facade or the CLI with cell-level merge, load them and call `ThreeWayMerge` yourself. The result is an ordinary `MergeResult`, so the facade can still apply it, and `MergePlanBuilder` turns it into a [merge plan](/docs/merge/plans):

```php
use Merql\CellMerge\CellMergeConfig;
use Merql\Merge\ThreeWayMerge;
use Merql\Merql;
use Merql\Plan\MergePlanBuilder;
use Merql\Snapshot\SnapshotStore;

$base   = SnapshotStore::load('base');
$ours   = SnapshotStore::load('ours');
$theirs = SnapshotStore::load('theirs');

$result = (new ThreeWayMerge(CellMergeConfig::auto()))->merge($base, $ours, $theirs);

$plan = (new MergePlanBuilder())->build('plan-1', $base, $ours, $theirs, $result);
```

Cell mergers only run for columns that both sides changed. When only one side changed a column, its value is taken as is.

## CellMergeConfig

`CellMergeConfig` maps columns or column types to cell merger implementations. The `auto()` factory provides sensible defaults:

```php
use Merql\CellMerge\CellMergeConfig;
use Merql\CellMerge\JsonCellMerger;
use Merql\CellMerge\TextCellMerger;

// Auto: column types containing "text" (TEXT, MEDIUMTEXT, LONGTEXT, ...) use
// TextCellMerger, column types containing "json" use JsonCellMerger.
$config = CellMergeConfig::auto();

// Custom: assign mergers to specific columns.
$config = (new CellMergeConfig())
    ->forColumn('posts.content', new TextCellMerger())
    ->forColumn('settings', new JsonCellMerger())
    ->forType('json', new JsonCellMerger());
```

Merger resolution priority:

1. Qualified column name (`table.column`).
2. Unqualified column name (`column`).
3. Column type pattern match (case-insensitive `str_contains`).
4. Default: `OpaqueCellMerger` (always reports a conflict when values differ).

## TextCellMerger

Merges multi-line text using Myers diff, the same algorithm git uses for file content. Each line is treated as a unit, and changes to different lines merge cleanly.

```php
use Merql\CellMerge\TextCellMerger;

$merger = new TextCellMerger();
```

### Example: clean text merge

```
Base:    "line one\nline two\nline three"
Ours:    "LINE ONE\nline two\nline three"    // changed line 1
Theirs:  "line one\nline two\nLINE THREE"    // changed line 3
```

Different lines were edited. The merge produces `"LINE ONE\nline two\nLINE THREE"` with no conflict.

### Example: text conflict

```
Base:    "line one\nline two"
Ours:    "changed by us\nline two"     // changed line 1
Theirs:  "changed by them\nline two"   // also changed line 1
```

Both sides changed the same line to different values. The merger returns a conflict result. The merged content includes conflict markers similar to git.

### How it works

`TextCellMerger` delegates to pitmaster's `ThreeWayMerge::merge()`, which computes a Myers diff between base and each side, then combines the two edit scripts. When edits overlap on the same line region, it reports a conflict.

## JsonCellMerger

Merges JSON objects by comparing top-level keys independently. Each key is merged using the standard three-way rules.

```php
use Merql\CellMerge\JsonCellMerger;

$merger = new JsonCellMerger();
```

### Example: clean JSON merge

```json
// Base
{"name": "Alice", "role": "editor", "active": true}

// Ours: changed "role"
{"name": "Alice", "role": "admin", "active": true}

// Theirs: changed "name"
{"name": "Bob", "role": "editor", "active": true}
```

Different keys were changed. The merged result is:

```json
{"name": "Bob", "role": "admin", "active": true}
```

### Example: JSON conflict

```json
// Base
{"color": "red"}

// Ours
{"color": "blue"}

// Theirs
{"color": "green"}
```

Both sides changed the `color` key to different values. This is a conflict. The merger defaults to ours and reports one conflict.

### Key-level rules

| Base | Ours | Theirs | Result |
|---|---|---|---|
| Key unchanged | Key unchanged | Accept base |
| Key unchanged | Key changed | Accept theirs |
| Key changed | Key unchanged | Accept ours |
| Key changed (same) | Key changed (same) | Accept (agree) |
| Key changed (different) | Key changed (different) | **Conflict** (default: ours) |
| Key exists | Key exists | Key removed | Accept removal |
| Key exists | Key removed | Key exists | Accept removal |
| Key missing | Key added | Key missing | Accept addition |
| Key missing | Key missing | Key added | Accept addition |

Nested objects are compared as opaque JSON strings, not recursively. If a nested object differs, the entire value of that key is compared, not its sub-keys.

### Requirements

All three values must be valid JSON objects or arrays. If any value is not valid JSON, or is a JSON scalar (string, number, boolean), the merger falls back to a conflict.

## OpaqueCellMerger

The default merger when no cell-level strategy is configured. It treats the column value as an opaque blob and always reports a conflict when both sides differ.

```php
use Merql\CellMerge\OpaqueCellMerger;

$merger = new OpaqueCellMerger();
$result = $merger->merge($base, $ours, $theirs);
// $result->clean is always false when $ours !== $theirs
```

## CellMergeResult

All cell mergers return a `CellMergeResult`:

```php
$result = $merger->merge($base, $ours, $theirs);

$result->clean;     // bool: true if merge resolved cleanly
$result->value;     // mixed: the merged value (or ours on conflict)
$result->conflicts; // int: number of conflicts within the cell
```

Factory methods:

```php
CellMergeResult::resolved($value);           // clean merge
CellMergeResult::conflict($oursValue, $n);   // n conflicts, defaults to ours
```

## Custom cell mergers

Implement the `CellMerger` interface to add your own merge strategy:

```php
use Merql\CellMerge\CellMerger;
use Merql\CellMerge\CellMergeResult;

class YamlCellMerger implements CellMerger
{
    public function merge(mixed $base, mixed $ours, mixed $theirs): CellMergeResult
    {
        // Parse YAML, merge keys, return result.
        $merged = $this->mergeYaml($base, $ours, $theirs);

        if ($merged['clean']) {
            return CellMergeResult::resolved($merged['value']);
        }

        return CellMergeResult::conflict($ours);
    }
}

$config = (new CellMergeConfig())
    ->forColumn('config_data', new YamlCellMerger());
```
