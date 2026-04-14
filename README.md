<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="./docs/public/logo-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="./docs/public/logo-light.svg">
    <img alt="Merql" src="./docs/public/logo-light.svg" height="56">
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
- Cell-level merge for TEXT (line-by-line via Myers diff) and JSON (key-by-key) columns
- Parameterized SQL generation with FK-aware ordering
- Pluggable database drivers for MySQL, SQLite, and any PDO-supported engine

## Quick Start

```bash
composer require merql/merql
```

```php
use Merql\Connection;
use Merql\Merql;

// Initialize with any PDO connection.
Merql::init(Connection::sqlite('/path/to/db.sqlite'));

// Capture database state at key points.
Merql::snapshot('base');
// ... ours makes changes ...
Merql::snapshot('ours');
// ... theirs makes changes ...
Merql::snapshot('theirs');

// Three-way merge.
$result = Merql::merge('base', 'ours', 'theirs');

if ($result->isClean()) {
    Merql::apply($result);
} else {
    foreach ($result->conflicts() as $conflict) {
        echo "{$conflict->table()}.{$conflict->column()}: "
            . "ours={$conflict->oursValue()}, theirs={$conflict->theirsValue()}\n";
    }
}
```

## PHP API

```php
use Merql\CellMerge\CellMergeConfig;
use Merql\Merge\ConflictPolicy;
use Merql\Merge\ConflictResolver;
use Merql\Merge\ThreeWayMerge;
use Merql\Apply\DryRun;

// Three-way merge with cell-level merge for TEXT and JSON columns
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

Merql merges at four levels of granularity:

| Level | Unit | Conflict when |
|---|---|---|
| Table | Whole table | One side adds, other removes |
| Row | Row by PK | Both insert same PK |
| Column | Column value | Both change same column to different values |
| Cell | Content within value | Both change same line (text) or key (JSON) |

Column-level merge is the key advantage over naive row-level comparison. When both sides change the same row but different columns, merql resolves it cleanly:

```
Base:    { title: "Hello",     content: "Body",    status: "draft"   }
Ours:    { title: "Hello",     content: "Body v2", status: "draft"   }
Theirs:  { title: "New Title", content: "Body",    status: "publish" }
Result:  { title: "New Title", content: "Body v2", status: "publish" }
```

## CLI

```bash
# Set connection (SQLite)
export MERQL_DB_DSN="sqlite:/path/to/db.sqlite"

# Or MySQL
export MERQL_DB_NAME=mydb MERQL_DB_USER=root

# Snapshot, diff, merge
vendor/bin/merql snapshot base
vendor/bin/merql diff base current
vendor/bin/merql merge base ours theirs
vendor/bin/merql merge base ours theirs --dry-run
```

## Documentation

The repo includes a dedicated docs app under [`docs/`](docs) that mirrors the same release/docs structure used in sibling projects.

```bash
cd docs
npm install
npm run dev
```

Topics covered:

- Getting started, CLI reference, and PHP API
- Three-way merge, column-level merge, and cell-level merge
- Conflict detection and resolution
- SQL generation, dry run, and database drivers
- Row identity, filters, schema validation, and testing strategy

## Testing

Merql is validated with unit tests, integration tests against real SQLite, and an oracle-style regression corpus.

```bash
# PHPUnit unit + integration tests
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

Current local verification baseline:

- `195` PHPUnit tests (179 unit + 16 integration)
- `420` assertions
- oracle regression summary `32/32`

## Features

| Category | Features |
|----------|----------|
| Merge | three-way merge, two-way patch, column-level resolution, cell-level merge |
| Cell Merge | TEXT line-by-line (Myers diff via pitmaster), JSON key-by-key, custom mergers |
| Conflicts | update/update, update/delete, delete/update, insert/insert, manual + auto resolve |
| SQL | parameterized INSERT/UPDATE/DELETE, FK-aware ordering, dry-run preview, transactions |
| Databases | MySQL, SQLite built-in, extensible to any PDO driver |
| Identity | primary key, natural key, content hash, composite key support |
| Snapshot | row fingerprinting, JSON persistence, schema capture, table/column/row filters |
| Validation | schema mismatch detection, snapshot name validation, path traversal protection |

## Architecture

```text
src/
├── Merql.php                  # Static facade (init, snapshot, diff, merge, apply)
├── Snapshot/                  # Capture database state (fingerprints + data)
├── Diff/                      # Compare two snapshots (insert/update/delete changesets)
├── Merge/                     # Three-way merge with column-level conflict resolution
├── CellMerge/                 # Cell-level merge (text, JSON, custom)
├── Apply/                     # SQL generation, dry run, FK ordering, applier
├── Driver/                    # Database driver interface (MySQL, SQLite)
├── Schema/                    # Table schema, validation, primary key resolution
├── Identity/                  # Row identity strategies (PK, natural key, hash)
├── Filter/                    # Table, column, and row filters
├── Connection.php             # PDO connection builder
└── Exceptions/                # Typed exceptions
```

## Requirements

- PHP 8.2+
- `ext-pdo` (built-in on virtually every PHP install)

## License

MIT
