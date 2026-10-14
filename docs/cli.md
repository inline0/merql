---
title: "CLI"
description: "Full reference for the merql command-line interface. Snapshot, diff, and merge databases from the terminal."
path: "cli"
order: 170
section: "Reference"
meta_title: "CLI"
meta_description: "Full reference for the merql command-line interface. Snapshot, diff, and merge databases from the terminal."
---

# CLI Reference

merql ships with a CLI for capturing snapshots, computing diffs, and running merges directly from the terminal. Composer installs it as `vendor/bin/merql`; in a checkout of the merql repository it is `bin/merql`.

`vendor/bin/merql` works from a dependency install since merql 0.4.1. Earlier releases looked for the Composer autoloader only in the package's own `vendor/` directory and failed when merql was installed as a dependency.

## Quick start

```bash
# Capture a baseline from your database.
export MERQL_DB_DSN="sqlite:/path/to/db.sqlite"
vendor/bin/merql snapshot base

# Make some changes, then capture again.
vendor/bin/merql snapshot current

# See what changed.
vendor/bin/merql diff base current
```

A three-way merge needs ours and theirs captured from two databases that diverged from base. Point `MERQL_DB_DSN` at the right database for each snapshot:

```bash
# Base: live before the sandbox was copied from it.
MERQL_DB_DSN="sqlite:/data/live.sqlite" vendor/bin/merql snapshot base

# ... copy live.sqlite to sandbox.sqlite, then both databases change ...

MERQL_DB_DSN="sqlite:/data/sandbox.sqlite" vendor/bin/merql snapshot ours
MERQL_DB_DSN="sqlite:/data/live.sqlite" vendor/bin/merql snapshot theirs

# Three-way merge with dry-run preview.
vendor/bin/merql merge base ours theirs --dry-run
```

Taking all three snapshots from the same database one after another does not work as a merge: theirs would already contain ours' changes, so every row ours inserted would be reported as an `insert_insert` conflict.

## Environment variables

The CLI reads database connection details from environment variables. You can
connect using either a PDO DSN string or individual MySQL parameters.

### DSN-based connection

Use `MERQL_DB_DSN` for PDO-backed connections. This is the recommended approach
for SQLite and for non-standard configurations. The DSN must point at a MySQL
or SQLite database: the CLI has no way to register a driver for other engines.

| Variable | Description | Example |
|---|---|---|
| `MERQL_DB_DSN` | Full PDO DSN string | `sqlite:/path/to/db.sqlite` |
| `MERQL_DB_USER` | Username (optional with DSN) | `root` |
| `MERQL_DB_PASS` | Password (optional with DSN) | `secret` |

```bash
# SQLite file.
export MERQL_DB_DSN="sqlite:/var/data/myapp.sqlite"

# MySQL via DSN.
export MERQL_DB_DSN="mysql:host=127.0.0.1;dbname=mydb;charset=utf8mb4"
export MERQL_DB_USER="root"
export MERQL_DB_PASS="secret"
```

Use a file-backed SQLite database. Each CLI command runs in its own process, so
`sqlite::memory:` always opens a new, empty database and a snapshot of it
contains no tables.

### MySQL shorthand

When `MERQL_DB_DSN` is not set, the CLI falls back to MySQL-specific variables.

| Variable | Default | Description |
|---|---|---|
| `MERQL_DB_NAME` | (required) | Database name |
| `MERQL_DB_HOST` | `127.0.0.1` | MySQL host |
| `MERQL_DB_PORT` | `3306` | MySQL port |
| `MERQL_DB_USER` | `root` | MySQL username |
| `MERQL_DB_PASS` | (empty) | MySQL password |
| `MERQL_DB_ADAPTER` | `pdo` | Set to `mysqli` to use the mysqli adapter |

```bash
export MERQL_DB_NAME="wordpress"
export MERQL_DB_HOST="127.0.0.1"
export MERQL_DB_PORT="3306"
export MERQL_DB_USER="root"
export MERQL_DB_PASS="password"
```

## Commands

### `merql snapshot <name>`

Capture the current state of the database named by the environment variables and save it as a named snapshot. This is the only command that connects to a database.

```bash
vendor/bin/merql snapshot baseline
```

Output:

```
Snapshot 'baseline' saved
Tables: posts, comments, users, settings
```

Snapshots are persisted as JSON files in `.merql/snapshots/` under the working directory (see [Snapshot storage](#snapshot-storage)). The name must contain only alphanumeric characters, dashes, and underscores.

Typical workflow: capture a snapshot before making changes, then capture another after. The two snapshots form the basis of a diff or merge.

### `merql diff <base> <current>`

Compare two named snapshots and display a summary of differences.

```bash
vendor/bin/merql diff baseline current
```

Output:

```
Changeset: baseline -> current
  Inserts: 3
  Updates: 7
  Deletes: 1
```

Both snapshots must already exist (previously captured with `merql snapshot`). The diff does not require a database connection since it operates on saved snapshot files.

### `merql merge <base> <ours> <theirs>`

Perform a three-way merge of three named snapshots.

```bash
vendor/bin/merql merge base ours theirs
```

Output:

```
Merge: base + ours + theirs
  Operations: 12
  Conflicts:  0
  Clean:      yes
```

All three snapshots must already exist. The merge does not require a database connection since it operates on saved snapshot files, and it never writes to a database: the CLI has no apply command. Use the PHP API to apply a merge result.

The CLI merges at the column level by default. Pass `--cell-merge` to merge
TEXT fields line by line and JSON fields key by key:

```bash
vendor/bin/merql merge base ours theirs --cell-merge --dry-run
```

#### `--dry-run`

Add the `--dry-run` flag to preview the SQL statements that the merge would generate, without executing anything. The statements describe how to turn the base state into the merged result, so they include the changes of both sides.

```bash
vendor/bin/merql merge base ours theirs --dry-run
```

Output:

```
Merge: base + ours + theirs
  Operations: 3
  Conflicts:  0
  Clean:      yes

Dry run SQL:
  INSERT INTO `posts` (`id`, `title`, `status`) VALUES ('10', 'New Post', 'draft');
  UPDATE `posts` SET `title` = 'Updated Title' WHERE `id` = '5';
  DELETE FROM `comments` WHERE `id` = '3';
```

## Development commands

A checkout of the merql repository has more scripts in `bin/` for its oracle-driven test suite. They are not part of the Composer package, so a dependency install only has `vendor/bin/merql`. Run them from the repository root after `composer install`.

| Command | Description |
|---|---|
| `./bin/oracle <name>` | Compute expected merge result for a scenario |
| `./bin/actual <name>` | Run merql on a scenario and capture output |
| `./bin/compare <name>` | Diff oracle vs actual output |
| `./bin/test-scenario <name>` | Full pipeline: setup, oracle, actual, compare |
| `./bin/test-regression` | Run all scenarios |
| `./bin/test-regression --category clean` | Run one category: `clean`, `conflict`, `identity`, `types`, `scale`, or `edge` |
| `./bin/test-regression --fast` | Pass/fail only, no reports |
| `./bin/verify-compliance` | Full compliance report |
| `./bin/verify-all` | PHPStan + code style + PHPUnit + oracle regression |

`bin/test-regression --jobs 4` runs up to four isolated PHP workers and prints
results in scenario order. `--jobs` requires a positive integer. An unknown
`--category` prints `No scenarios found` and exits successfully.

See [Testing Strategy](/docs/advanced/testing) for the scenario layout.

## Exit status

`merql snapshot`, `diff`, and `merge` exit with status 1 when an argument is
missing, and `snapshot` also when no connection is configured. Missing snapshots
and unknown commands print a one-line error to stderr and exit 1.

## Snapshot storage

Snapshots are stored in `.merql/snapshots/` relative to the working directory. Each snapshot is a single JSON file named `<name>.json`. Run every command from the same directory so they find each other's snapshots.

Set `MERQL_SNAPSHOT_DIR` to use another directory for every CLI command:

```bash
export MERQL_SNAPSHOT_DIR="/var/data/merql-snapshots"
```

In PHP, set the directory before capturing or loading snapshots:

```php
use Merql\Snapshot\SnapshotStore;

SnapshotStore::setDirectory('/var/data/merql-snapshots');
```

Snapshot files are portable. You can copy them between machines, commit them to version control, or share them for collaborative debugging. A snapshot captured by the CLI can be loaded in PHP with `SnapshotStore::load()`, and the other way around, as long as both use the same directory.
