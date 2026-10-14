<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="./.github/logo-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="./.github/logo-light.svg">
    <img alt="Merql" src="./.github/logo-light.svg" height="56">
  </picture>
</p>

<p align="center">
  Pure PHP three-way database merge with column-level conflict resolution
</p>

<p align="center">
  <a href="https://github.com/inline0/merql/actions/workflows/ci.yml"><img src="https://github.com/inline0/merql/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://packagist.org/packages/merql/merql"><img src="https://img.shields.io/packagist/v/merql/merql.svg" alt="Packagist"></a>
  <a href="https://github.com/inline0/merql/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="license"></a>
</p>

---

## What is Merql?

Merql is a pure PHP three-way database merge engine. It takes three database states (base, ours, theirs), computes changesets, and produces a merged result with conflict detection. Git-style merge semantics applied to MySQL and SQLite tables, at the column and cell level.

**The problem:** when two systems independently modify the same database, reconciling the changes requires understanding what each side added, changed, and deleted relative to a common ancestor. Without a common base, you cannot distinguish "added" from "unchanged."

**Merql solves this** by applying the same three-way merge algorithm that git uses for files, but operating on rows and columns instead of lines:

- Snapshot database state with row fingerprinting for fast change detection
- Compute per-column changesets between any two snapshots
- Three-way merge with column-level conflict resolution
- UI-ready merge plans with stable operation and change group IDs
- Selected operation apply for staging workflows
- Rollback plan generation with drift checks
- Opt-in cell-level merge for TEXT (line-by-line via Myers diff) and JSON (key-by-key) columns
- Parameterized SQL generation with FK-aware ordering
- Guarded apply with optimistic live-row preconditions
- Connection adapters for PDO and mysqli, with built-in MySQL and SQLite dialects

## Quick Start

```bash
composer require merql/merql
```

```php
use Merql\Connection;
use Merql\Merql;

$live    = Connection::sqlite('/data/live.sqlite');
$sandbox = Connection::sqlite('/data/sandbox.sqlite');

// Base: live at the moment the sandbox was copied from it.
Merql::init($live);
Merql::snapshot('base');

// ... copy live.sqlite to sandbox.sqlite, then both databases change ...

// Ours: the edited sandbox. Theirs: live with its own changes.
Merql::init($sandbox);
Merql::snapshot('ours');
Merql::init($live);
Merql::snapshot('theirs');

// Three-way merge.
$result = Merql::merge('base', 'ours', 'theirs');

if ($result->isClean()) {
    foreach ($result->operations() as $operation) {
        echo "{$operation->type} {$operation->table} #{$operation->rowKey} ({$operation->source})\n";
    }
} else {
    foreach ($result->conflicts() as $conflict) {
        echo "{$conflict->type()} {$conflict->table()} #{$conflict->rowKey()} {$conflict->column()}\n";
    }
}
```

Take each snapshot from the database that holds that state. Taken from one
database in sequence, theirs would already contain ours' changes and every row
ours inserted would be reported as an `insert_insert` conflict.

The result turns base into the merged state and carries the changes of both
sides. `Merql::apply($result)` executes all of it against the connected
database, which works for a database that still holds base. To write the
sandbox's changes into live, select the operations live is missing and use
guarded apply, as below.

## Merge Plans And Rollback

Use merge plans when a host application needs to inspect, stage, or selectively
apply database changes before writing to the target database.

```php
use Merql\Connection;
use Merql\Identity\IdentityRule;
use Merql\Identity\IdentityRuleSet;
use Merql\Merql;
use Merql\Plan\OperationSelection;
use Merql\Plan\SelectedMergeResultFactory;
use Merql\Rollback\RollbackPlanBuilder;
use Merql\Snapshot\SnapshotStore;
use Merql\Snapshot\Snapshotter;

$rules = new IdentityRuleSet([
    'wp_options' => IdentityRule::natural(['option_name']),
]);

$live = Connection::sqlite('/data/live.sqlite');
$sandbox = Connection::sqlite('/data/sandbox.sqlite');

// base: live before the sandbox copy; ours: the sandbox; theirs: live now.
SnapshotStore::save((new Snapshotter($live, identityRules: $rules))->capture('base', ['wp_options']));
// ... copy live to the sandbox, then both databases change ...
SnapshotStore::save((new Snapshotter($sandbox, identityRules: $rules))->capture('ours', ['wp_options']));
SnapshotStore::save((new Snapshotter($live, identityRules: $rules))->capture('theirs', ['wp_options']));

$plan = Merql::plan('plan-1', 'base', 'ours', 'theirs');

// Live already holds theirs. Select what it is missing (ours, and merged
// updates), leaving out rows with unresolved conflicts.
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
$selection = OperationSelection::fromIds($ids);

$selected = (new SelectedMergeResultFactory())
    ->fromOperationSelection($plan, $selection, SnapshotStore::load('base'));

// Before rows come from the live state the write will overwrite.
$expectedLive = SnapshotStore::load('theirs');
$liveBeforeRows = [];
foreach ($plan->operations as $operation) {
    if ($selection->contains($operation->id)) {
        $liveBeforeRows[$operation->id] = $expectedLive
            ->getTable($operation->table)
            ?->getRow($operation->rowKey);
    }
}

$rollback = (new RollbackPlanBuilder())->build('rollback-1', $plan, $selection, $liveBeforeRows);

Merql::init($live);
$applied = Merql::applyGuarded($selected, $expectedLive);
```

For prefixed or multi-environment tables, capture physical table names under a
canonical name before merging:

```php
use Merql\Connection;
use Merql\Identity\IdentityRule;
use Merql\Identity\IdentityRuleSet;
use Merql\Snapshot\SnapshotStore;
use Merql\Snapshot\Snapshotter;

$connection = Connection::sqlite('/path/to/db.sqlite');
$snapshotter = new Snapshotter($connection, identityRules: new IdentityRuleSet([
    'wp_posts' => IdentityRule::primary(['ID']),
]));

SnapshotStore::save($snapshotter->captureAliased('sandbox', [
    'wp_onumia_abcd_posts' => 'wp_posts',
]));
```

## PHP API

```php
use Merql\CellMerge\CellMergeConfig;
use Merql\Merge\ConflictPolicy;
use Merql\Merge\ConflictResolver;
use Merql\Merge\ThreeWayMerge;
use Merql\Apply\DryRun;

// Three-way merge with cell-level merge for TEXT and JSON columns.
// The facade also accepts CellMergeConfig; the CLI uses --cell-merge.
$merge = new ThreeWayMerge(CellMergeConfig::auto());
$result = $merge->merge($base, $ours, $theirs);

// Two-way merge (apply changes onto base, never conflicts)
$result = $merge->patch($base, $changes);

// Resolve conflicts programmatically
$resolved = ConflictResolver::resolve($result, ConflictPolicy::TheirsWins);

// Preview SQL without executing
$sql = DryRun::generate($result);
foreach ($sql as $statement) {
    echo $statement . ";\n";
}
```

## How It Works

```
         Base (common ancestor)
        /                       \
   Ours (our changes)      Theirs (their changes)
        \                       /
         ─────── MERGE ────────
                   │
            Merged result
```

Merql detects conflicts at three levels of granularity:

| Level | Unit | Conflict when |
|---|---|---|
| Row | Row by identity key | Both insert the same key (`insert_insert`), or one side updates a row the other deletes (`update_delete`, `delete_update`) |
| Column | Column value | Both change the same column to different values (`update_update`) |
| Cell | Content within value (opt-in) | Both change the same line (text) or key (JSON) |

Tables are not a merge unit of their own. A table missing from one snapshot is
diffed as if all of its rows were inserted or deleted, and the row rules apply.

Column-level merge is the key advantage over naive row-level comparison. When both sides change the same row but different columns, merql resolves it cleanly:

```
Base:    { title: "Hello",     content: "Body",    status: "draft"   }
Ours:    { title: "Hello",     content: "Body v2", status: "draft"   }
Theirs:  { title: "New Title", content: "Body",    status: "publish" }
Result:  { title: "New Title", content: "Body v2", status: "publish" }
```

## CLI

Requires merql 0.4.1 or later, the first release where `vendor/bin/merql`
finds the autoloader when installed as a dependency.

```bash
# Connection: SQLite through a PDO DSN ...
export MERQL_DB_DSN="sqlite:/data/live.sqlite"
# ... or MySQL
export MERQL_DB_NAME=mydb MERQL_DB_USER=root

# Snapshot and diff
vendor/bin/merql snapshot base
# ... change the database ...
vendor/bin/merql snapshot current
vendor/bin/merql diff base current

# Three-way merge: snapshot ours and theirs from the two diverged databases
MERQL_DB_DSN="sqlite:/data/sandbox.sqlite" vendor/bin/merql snapshot ours
MERQL_DB_DSN="sqlite:/data/live.sqlite" vendor/bin/merql snapshot theirs
vendor/bin/merql merge base ours theirs --dry-run
```

`diff` and `merge` read saved snapshots from `.merql/snapshots/` in the working
directory and need no connection. The CLI has no apply command.

## Documentation

The portable Markdown documentation lives under [`docs/`](docs), with `meta.json` files describing the navigation.

```bash
node scripts/check-docs-content.mjs
```

Topics covered:

- Getting started, CLI reference, and PHP API
- Three-way merge, merge plans, column-level merge, and cell-level merge
- Conflict detection and resolution
- SQL generation, guarded apply, rollback, dry run, and database drivers
- Row identity, identity rules, filters, schema validation, and testing strategy

## Testing

Merql is validated with unit tests, integration tests against real SQLite, and an oracle-style regression corpus.

```bash
# PHPUnit unit + integration tests and the complete oracle corpus
composer test

# Oracle regression corpus
composer test:oracle

# Static analysis
composer analyse

# Coding standards
composer cs

# Full release-grade verification (analyse + cs + test + test:oracle)
composer verify
```

CI runs coding standards, PHPStan, PHPUnit, the complete oracle corpus, a
MySQL integration lane and dependency audit.

Current local verification baseline:

- `241` PHPUnit tests
- `590` assertions
- oracle regression summary `32/32`

## Features

| Category | Features |
|----------|----------|
| Merge | three-way merge, two-way patch, merge plans, selected apply, column-level resolution, cell-level merge |
| Cell Merge | TEXT line-by-line (Myers diff via pitmaster), JSON key-by-key, custom mergers |
| Conflicts | update/update, update/delete, delete/update, insert/insert, manual + auto resolve |
| SQL | parameterized INSERT/UPDATE/DELETE, FK-aware ordering, guarded apply, dry-run preview, transactions |
| Databases | MySQL and SQLite dialects, PDO and mysqli connection adapters |
| Identity | primary key, natural key, content hash, composite key support, identity rule registry |
| Snapshot | row fingerprinting, JSON persistence, schema capture, aliased table capture, table/column/row filters |
| Rollback | rollback plan generation, drift checks, inverse operation apply |
| Validation | schema mismatch detection, snapshot name validation, path traversal protection |

## Architecture

```text
src/
├── Merql.php                  # Static facade (init, snapshot, diff, merge, apply)
├── Snapshot/                  # Capture database state (fingerprints + data)
├── Diff/                      # Compare two snapshots (insert/update/delete changesets)
├── Merge/                     # Three-way merge with column-level conflict resolution
├── Plan/                      # Merge plans, selections, selected merge results
├── Rollback/                  # Rollback plans, drift checks, inverse apply
├── CellMerge/                 # Cell-level merge (text, JSON, custom)
├── Apply/                     # SQL generation, dry run, FK ordering, applier
├── Database/                  # Connection interface plus PDO and mysqli adapters
├── Driver/                    # Database dialect interface (MySQL, SQLite)
├── Schema/                    # Table schema, validation, primary key resolution
├── Identity/                  # Row identity strategies (PK, natural key, hash)
├── Filter/                    # Table, column, and row filters
├── Connection.php             # Connection adapter builders
└── Exceptions/                # Typed exceptions
```

## Requirements

- PHP 8.2+
- `ext-mbstring` and `ext-zlib` (required by `pitmaster/pitmaster`)
- One database extension for the adapter in use: `ext-pdo` with `ext-pdo_sqlite` or `ext-pdo_mysql`, or `ext-mysqli`
- MySQL or SQLite; other engines need a custom driver

## License

MIT
