---
title: "Introduction"
description: "Pure PHP three-way database merge with column-level conflict resolution. Git-style merge semantics applied to relational data."
path: "."
order: 10
meta_title: "Introduction"
meta_description: "Pure PHP three-way database merge with column-level conflict resolution. Git-style merge semantics applied to relational data."
---

# Introduction

merql is a pure PHP library that performs three-way merges on database state. It snapshots tables, computes changesets between snapshots, merges two sets of changes against a common base, and detects conflicts at the column level. The same algorithm git uses for source files, applied to rows and columns instead of lines.

No external tools. Supports MySQL and SQLite through pluggable connection adapters and dialect drivers.

## Quick example

A merge needs three states of the same data: the base both copies started from, and the two copies after they changed independently. Here a sandbox was copied from the live database, and both have been edited since.

```php
use Merql\Connection;
use Merql\Merql;

$live    = Connection::sqlite('/data/live.sqlite');
$sandbox = Connection::sqlite('/data/sandbox.sqlite');

// Base: live at the moment the sandbox was copied from it.
Merql::init($live);
Merql::snapshot('base');

// ... copy live.sqlite to sandbox.sqlite, then both databases change ...

// Ours: the sandbox with our edits. Theirs: live with its own changes.
Merql::init($sandbox);
Merql::snapshot('ours');
Merql::init($live);
Merql::snapshot('theirs');

$result = Merql::merge('base', 'ours', 'theirs');

if ($result->isClean()) {
    foreach ($result->operations() as $operation) {
        echo "{$operation->type} {$operation->table} #{$operation->rowKey} ({$operation->source})\n";
    }
} else {
    echo "Conflicts: {$result->conflictCount()}\n";
}
```

Each snapshot is taken from the database that holds that state. Taking all three from one database in sequence does not describe two diverged copies: theirs would already contain ours' changes, and every row ours inserted would be reported as an `insert_insert` conflict.

The result lists the operations that turn base into the merged state, from both sides, each marked with its source (`ours`, `theirs`, or `merged`). To write the sandbox's changes into live, apply the operations live does not have yet with [Guarded Apply](/docs/apply/guarded).

Or from the CLI, which connects to the database named in `MERQL_DB_DSN` for each `snapshot`:

```bash
MERQL_DB_DSN="sqlite:/data/live.sqlite" vendor/bin/merql snapshot base
# ... copy live.sqlite to sandbox.sqlite, then both databases change ...
MERQL_DB_DSN="sqlite:/data/sandbox.sqlite" vendor/bin/merql snapshot ours
MERQL_DB_DSN="sqlite:/data/live.sqlite" vendor/bin/merql snapshot theirs

vendor/bin/merql merge base ours theirs --dry-run
```

## Why merql

Most database tools that compare state work at the row level: if any column in a row differs between two versions, the entire row is flagged as a conflict. This produces false conflicts when two people edit different columns of the same row.

merql merges at the column level. If one side changed the `title` and the other changed the `status`, those changes merge cleanly without conflict. A conflict is only raised when both sides change the same column to different values.

For TEXT and JSON columns, merql can go one level deeper with cell-level merge. Two edits to different lines of a text column, or different keys of a JSON column, can merge cleanly using the same three-way algorithm. Cell-level merge is opt-in: pass a `CellMergeConfig` to `ThreeWayMerge` or the facade, or use `--cell-merge` in the CLI. See [Cell-Level Merge](/docs/merge/cell-level).

## The merge model

```
         Base (snapshot at fork time)
        /                              \
   Ours (one copy's changes)            Theirs (the other copy's changes)
        \                              /
         --------- MERGE ----------
                     |
              Merged result
              + conflicts (if any)
```

Three states, same as git. Base is the common ancestor. Ours and theirs are the two copies after they diverged, each captured from its own database. The merge rules treat both sides alike. The names show up in each operation's `source`, in conflict types (`update_delete` means ours updated and theirs deleted), and in the `OursWins` and `TheirsWins` resolution policies. Without a common base, you cannot distinguish "added" from "unchanged."

A merge writes nothing by itself. Its operations transform base into the merged state, so `Merql::apply()` runs all of them cleanly only against a database that still holds base. To bring one side's changes into a database that already holds the other side, such as promoting sandbox edits into live, apply only the operations it is missing; see [Guarded Apply](/docs/apply/guarded).

## Requirements

- PHP 8.2 or later.
- `ext-mbstring` and `ext-zlib`, required by the `pitmaster/pitmaster` dependency that provides the text diff.
- A database extension for the adapter you use: `ext-pdo` with `ext-pdo_sqlite` or `ext-pdo_mysql`, or `ext-mysqli`. merql's `composer.json` only suggests `ext-pdo` and `ext-mysqli`, so Composer does not check for them.
- A MySQL or SQLite database. Other engines need a custom [driver](/docs/apply/drivers).

## What it is not

merql is not a schema migration tool. It does not replicate databases or synchronize them in real time. It solves one specific problem: given three database states, produce a merged fourth state with conflicts identified.

## Documentation

- [Getting Started](/docs/getting-started) -- installation, first snapshot, first merge.
- [CLI Reference](/docs/cli) -- full reference for the `vendor/bin/merql` command.
- [PHP API Reference](/docs/api) -- complete API documentation for all classes.
- [Merge Plans](/docs/merge/plans) -- stable operation IDs, change groups, and selections.
- [Guarded Apply](/docs/apply/guarded) -- selected writes with live-row preconditions.
- [Rollback](/docs/apply/rollback) -- rollback artifacts and drift checks.
