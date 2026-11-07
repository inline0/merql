---
title: "Column-Level Merge"
description: "How merql merges rows at the column level to avoid false conflicts when different columns are edited."
path: "merge/column-level"
order: 50
section: "Merge"
meta_title: "Column-Level Merge"
meta_description: "How merql merges rows at the column level to avoid false conflicts when different columns are edited."
---

# Column-Level Merge

Column-level merge is merql's primary advantage over naive row-level comparison. When both sides modify the same row, merql compares each column independently rather than flagging the entire row as a conflict.

## The problem with row-level merge

Consider a `posts` table where two people edit the same row:

```
Base:    post #42  { title: "Hello",     content: "Body",    status: "draft"   }
Ours:    post #42  { title: "Hello",     content: "Body v2", status: "draft"   }
Theirs:  post #42  { title: "New Title", content: "Body",    status: "publish" }
```

A row-level merge sees that both sides changed the row and declares a conflict. But the changes do not overlap at all. Ours edited `content`, theirs edited `title` and `status`.

## How column-level merge resolves it

merql applies the same three-way merge rules to each column individually:

```
title:   base="Hello", ours="Hello",  theirs="New Title"  -> accept theirs
content: base="Body",  ours="Body v2", theirs="Body"      -> accept ours
status:  base="draft", ours="draft",  theirs="publish"    -> accept theirs
```

Result: `{ title: "New Title", content: "Body v2", status: "publish" }`. Clean merge, no conflict.

## How it works

When `ThreeWayMerge` detects that both sides updated the same row, it delegates to `ColumnMerge::merge()`:

```php
use Merql\Merge\ColumnMerge;

$result = ColumnMerge::merge(
    table: 'posts',
    rowKey: '42',
    base: ['title' => 'Hello', 'content' => 'Body', 'status' => 'draft'],
    ours: ['title' => 'Hello', 'content' => 'Body v2', 'status' => 'draft'],
    theirs: ['title' => 'New Title', 'content' => 'Body', 'status' => 'publish'],
);

// $result['values']    -> merged column values
// $result['conflicts'] -> list of Conflict objects (empty if clean)
```

For each column, the same rules apply:

| Base | Ours | Theirs | Result |
|---|---|---|---|
| A | A | A | Keep base (no change) |
| A | A | B | Accept theirs |
| A | B | A | Accept ours |
| A | B | B | Accept (both agree) |
| A | B | C | **Conflict** (different values) |

## Which rows reach column merge

Column merge runs only for a row that both sides updated. Every other case is decided at the row level before any column is compared:

- A row only one side updated becomes an update with that side's full row and source `ours` or `theirs`.
- A row one side updated and the other deleted is an `update_delete` or `delete_update` conflict. There are no columns to compare against a deleted row, so the conflict carries the updated side's full row instead of a column value.
- A row both sides inserted with the same primary key is an `insert_insert` conflict, because there is no base row to compare against.

These structural conflicts have a `null` column and are described with their resolution rules in [Conflict Handling](/docs/merge/conflicts).

A row both sides updated always produces one update operation with source `merged`, whether or not any of its columns conflict. Its values hold every column of the row: the base value where neither side changed it, the changed side's value where one side did, and the merged or fallback value where both did. The columns of the three versions are compared as a union, and a column missing from one version counts as `NULL` for that version.

## When conflicts still happen

A column-level conflict occurs when both sides changed the same column to different values:

```
Base:    post #42  { title: "Hello" }
Ours:    post #42  { title: "Welcome" }
Theirs:  post #42  { title: "Greetings" }
```

Both changed `title`, to different values. This is a genuine conflict that requires resolution.

```php
$result = ColumnMerge::merge(
    'posts', '42',
    ['title' => 'Hello'],
    ['title' => 'Welcome'],
    ['title' => 'Greetings'],
);

// $result['conflicts'] contains one Conflict:
//   table: "posts", column: "title"
//   oursValue: "Welcome", theirsValue: "Greetings", baseValue: "Hello"
```

Each conflicted column is reported as its own `update_update` conflict with the table, row key, column name and the three values. Two conflicting columns in one row produce two conflicts. [Conflict Handling](/docs/merge/conflicts) lists the accessors and shows how to inspect them.

## Partial conflicts

A single row can have both clean merges and conflicts. If one column merges cleanly but another conflicts, the `MergeOperation` for the row contains the merged values for clean columns and a fallback value for conflicted columns. The conflict is still reported separately.

```
Base:    { title: "Hello", content: "Body",    status: "draft" }
Ours:    { title: "Welcome", content: "Body v2", status: "draft" }
Theirs:  { title: "Greetings", content: "Body",    status: "publish" }
```

Here `title` conflicts (both changed it differently), `content` merges clean (only ours changed), and `status` merges clean (only theirs changed). The operation carries:

```php
// values: { title: "Welcome", content: "Body v2", status: "publish" }
// One conflict on the "title" column.
```

Without a `CellMergeConfig`, the fallback in a conflicted column is ours' value, as above. With one, it is the value the cell merger returned: `OpaqueCellMerger` returns ours' value, `JsonCellMerger` returns the merged object with ours' value on each conflicting key (or ours' whole value when one version is not a JSON object or array), and `TextCellMerger` returns the merged text with git-style conflict markers around the lines both sides changed. The `Conflict` itself always carries the plain ours, theirs and base values.

### Resolving the remaining columns

The fallback value is a placeholder, not a decision. A result with conflicts is not clean, and the `Applier` refuses to apply it until they are resolved. `ConflictResolver::resolve()` with `ConflictPolicy::OursWins` or `ConflictPolicy::TheirsWins` patches only the conflicted columns of the `merged` operation with the winning side's value from each `Conflict`. The columns that merged cleanly keep their merged values, so in the example above `TheirsWins` gives `{ title: "Greetings", content: "Body v2", status: "publish" }`. For per-column decisions, read each conflict yourself and build the result. Both paths are covered in [Conflict Handling](/docs/merge/conflicts#automatic-resolution-with-conflictpolicy).

### Partial conflicts in a merge plan

A [merge plan](/docs/merge/plans) built from the result lists the row's `merged` operation once. `resultValues` holds the values above, including the fallback, and `changedColumns` lists the columns whose result value differs from the base row. The operation and its change group report `conflictState = 'conflicted'`, and a selection that includes them keeps the conflict, so it cannot be applied as clean. Leave conflicted rows out of the selection, or resolve the result first and build the plan from the resolved result, as [Merge Plans](/docs/merge/plans#selecting-what-a-target-is-missing) shows.

## Value comparison

Column values are compared as strings after casting. `NULL` is a distinct value:

- `NULL` to `NULL` = no change.
- `NULL` to `"hello"` = update.
- `"hello"` to `NULL` = update.
- `"1"` to `1` = no change (both cast to the string `"1"`).

This matches MerQL connection adapter fetch semantics: all database values come back as strings (or NULL).

## Cell-level merge

When column-level merge still results in a conflict (both sides changed the same column to different values), merql can go one level deeper with [cell-level merge](/docs/merge/cell-level). This applies to TEXT columns (line-by-line diff) and JSON columns (key-by-key diff), resolving cases where both sides edited different parts of the same column value.

Cell-level merge is a step inside column merge, not a separate pass. `ColumnMerge` asks the configured cell merger only for a column that both sides changed to different values. A clean cell result becomes the column's value with no conflict. A cell result that still conflicts becomes the fallback value described above and is reported as an ordinary `update_update` conflict for the column, so resolution and plans treat it like any other column conflict.

It is off by default. Pass a `CellMergeConfig` to `ThreeWayMerge`, to `Merql::init()` for every later facade merge, or to a single `Merql::merge()`, `Merql::patch()` or `Merql::plan()` call, or use `--cell-merge` with `merql merge`:

```php
use Merql\CellMerge\CellMergeConfig;
use Merql\Merql;

$result = Merql::merge('base', 'ours', 'theirs', cellMergeConfig: CellMergeConfig::auto());
```

With `CellMergeConfig::auto()`, a TEXT `content` column that ours edited on its first line and theirs on its last merges into one value with both edits, where plain column merge would report a conflict.

## Which page to read next

Column merge decides each column of a row both sides updated. What happens around it is covered on three pages:

- [Cell-Level Merge](/docs/merge/cell-level) applies when your conflicts are in TEXT or JSON columns that both sides edit in different places, such as post content or settings objects. It reduces the number of `update_update` conflicts but never removes them all: two edits to the same line or the same JSON key still conflict.
- [Conflict Handling](/docs/merge/conflicts) applies once a result has conflicts, from column merge or from the row-level cases above. It covers the four conflict types, the `Conflict` accessors and how `ConflictResolver` turns a conflicted result into one the `Applier` accepts.
- [Merge Plans](/docs/merge/plans) applies when the merged operations are reviewed, staged or applied in part instead of all at once. A plan shows each `merged` operation next to its base, ours and theirs rows, which is where a column merge decision can be checked before it is written.
