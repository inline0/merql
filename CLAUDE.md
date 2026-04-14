# merql

Pure PHP three-way database merge. Takes three database states (base, ours, theirs), computes changesets, and produces a merged result with conflict detection. Git-style merge semantics applied to MySQL tables. No extensions, no external tools.

## Quick Reference

```bash
# Testing (oracle-driven)
./bin/verify-all                         # Required final gate: analyse + cs + phpunit + oracle regression
./bin/test-scenario <name>               # Single scenario: setup → oracle → actual → compare
./bin/test-regression                    # All scenarios
./bin/test-regression --jobs 4           # Parallel
./bin/test-regression --category merge   # By category
./bin/test-regression --fast             # Pass/fail only, no reports
./bin/verify-compliance                  # Full compliance report

# Oracle management
./bin/oracle <name>                      # Compute expected merge result for scenario
./bin/actual <name>                      # Run merql, capture output
./bin/compare <name>                     # Diff oracle vs actual

# Unit tests
composer test:unit                       # Isolated component tests
composer test                            # Full phpunit + oracle matrix

# Code quality
composer cs                              # Check coding standards
composer cs:fix                          # Fix coding standards
composer analyse                         # PHPStan static analysis

# CLI
./bin/merql snapshot <name>              # Capture current database state
./bin/merql diff <base> <current>        # Show changeset between two snapshots
./bin/merql merge <base> <ours> <theirs> # Three-way merge
./bin/merql merge --dry-run ...          # Preview without applying
./bin/merql apply <merge-result>         # Apply a merge result
./bin/merql conflicts <merge-result>     # List unresolved conflicts
```

## Non-Negotiable Testing Rule

After every meaningful work pass, run the full matrix from the repo root before treating the work as done:

```bash
./bin/verify-all
```

No partial sign-off. Merge correctness must never regress.

## What This Is

A library that:

1. Snapshots database state (row fingerprints per table)
2. Computes changesets between two states (inserts, updates, deletes)
3. Performs three-way merge of two changesets against a common base
4. Detects conflicts at the column level (not just row level)
5. Generates SQL to apply the merged result
6. Supports dry-run preview and manual conflict resolution

All without external tools. Direct PDO for database access.

## What This Is Not

Not a schema migration tool (use Liquibase/Flyway). Not a replication engine (use MySQL replication). Not a sync tool (use pt-table-sync). merql solves one specific problem: given three database states, produce a merged fourth state with conflicts identified. The git merge algorithm, applied to relational data.

## The Merge Model

```
         Base (snapshot at fork time)
        /                              \
   Ours (source A, may have changed)    Theirs (source B, changes to merge in)
        \                              /
         ────────── MERGE ────────────
                     │
              Merged result
              + conflicts (if any)
```

Three states, same as git:

- **Base**: the database when the fork happened
- **Ours**: the database we're merging into (may have changed since fork)
- **Theirs**: the database we're merging from (the changes to apply)

### Operation detection

Compare base → theirs to detect what changed:

| Base | Theirs | Operation |
|---|---|---|
| Row exists | Row exists, same content | No change |
| Row exists | Row exists, different content | UPDATE (per-column diff) |
| Row exists | Row missing | DELETE |
| Row missing | Row exists | INSERT |

Same comparison for base → ours. Then merge the two changesets.

### Merge rules

| Ours | Theirs | Result |
|---|---|---|
| No change | No change | Keep base |
| No change | Updated | Accept theirs |
| Updated | No change | Accept ours |
| Updated (same value) | Updated (same value) | Accept (agree) |
| Updated (different value) | Updated (different value) | **Conflict** |
| No change | Deleted | Accept delete |
| Deleted | No change | Accept delete |
| Deleted | Deleted | Accept delete (agree) |
| Updated | Deleted | **Conflict** |
| Deleted | Updated | **Conflict** |
| Inserted | — | Accept insert (ours) |
| — | Inserted | Accept insert (theirs) |
| Inserted (same PK) | Inserted (same PK) | **Conflict** |

### Column-level merge (the key innovation)

Git merges lines. merql merges columns. This resolves cases that a row-level merge would flag as conflicts:

```
Base:    post #42  { title: "Hello",     content: "Body",    status: "draft"   }
Ours:    post #42  { title: "Hello",     content: "Body v2", status: "draft"   }
Theirs:  post #42  { title: "New Title", content: "Body",    status: "publish" }

Row-level: CONFLICT (both sides changed the row)
Column-level:
  title:   base="Hello", ours="Hello", theirs="New Title"     → accept theirs ✅
  content: base="Body",  ours="Body v2", theirs="Body"        → accept ours ✅
  status:  base="draft", ours="draft",  theirs="publish"      → accept theirs ✅

Result:  post #42  { title: "New Title", content: "Body v2", status: "publish" }
Clean merge. No conflict.
```

Column-level merge only conflicts when both sides changed the same column to different values.

## Project Structure

```
merql/
├── src/
│   ├── Merql.php                        # Static facade (public API entry point)
│   │
│   ├── Snapshot/
│   │   ├── Snapshotter.php              # Capture database state
│   │   ├── Snapshot.php                 # Readonly: table → row fingerprints + data
│   │   ├── RowFingerprint.php           # Hash of row content for fast change detection
│   │   ├── TableSnapshot.php            # Single table's rows and schema
│   │   └── SnapshotStore.php            # Persist/load snapshots (JSON or binary)
│   │
│   ├── Diff/
│   │   ├── Differ.php                   # Compare two snapshots → changeset
│   │   ├── Changeset.php                # Collection of operations
│   │   ├── RowInsert.php                # Readonly: table, primary key, column values
│   │   ├── RowUpdate.php                # Readonly: table, primary key, changed columns (old → new)
│   │   ├── RowDelete.php                # Readonly: table, primary key, old values
│   │   └── ColumnDiff.php              # Single column change (old value, new value)
│   │
│   ├── Merge/
│   │   ├── ThreeWayMerge.php            # Core merge algorithm (base + ours + theirs → result)
│   │   ├── MergeResult.php              # Readonly: clean operations + conflicts
│   │   ├── MergeOperation.php           # Single resolved operation (insert, update, delete)
│   │   ├── Conflict.php                 # Readonly: table, row, column, ours value, theirs value
│   │   ├── ConflictPolicy.php           # Enum: OursWins, TheirsWins, Manual
│   │   ├── ConflictResolver.php         # Apply a policy to resolve conflicts
│   │   └── ColumnMerge.php              # Per-column merge logic
│   │
│   ├── Apply/
│   │   ├── Applier.php                  # Execute merge result as SQL
│   │   ├── SqlGenerator.php             # Generate INSERT/UPDATE/DELETE statements
│   │   ├── DryRun.php                   # Preview: return SQL without executing
│   │   └── ApplyResult.php              # Readonly: rows affected, errors
│   │
│   ├── Schema/
│   │   ├── TableSchema.php              # Table structure (columns, types, primary key)
│   │   ├── SchemaReader.php             # Read schema from MySQL (INFORMATION_SCHEMA)
│   │   └── PrimaryKeyResolver.php       # Determine row identity (PK, unique key, or all columns)
│   │
│   ├── Identity/
│   │   ├── RowIdentity.php              # How to identify "the same row" across snapshots
│   │   ├── PrimaryKeyIdentity.php       # Match by primary key (default)
│   │   ├── NaturalKeyIdentity.php       # Match by unique columns (for tables without auto-increment)
│   │   └── ContentHashIdentity.php      # Match by content hash (for tables without keys)
│   │
│   ├── Filter/
│   │   ├── TableFilter.php              # Include/exclude tables from snapshot and merge
│   │   ├── ColumnFilter.php             # Ignore specific columns (e.g., updated_at timestamps)
│   │   └── RowFilter.php               # Ignore specific rows (e.g., transient data)
│   │
│   ├── Connection.php                   # PDO connection builder
│   └── Exceptions/
│       ├── SnapshotException.php
│       ├── MergeException.php
│       ├── ConflictException.php        # Thrown when unresolved conflicts remain on apply
│       └── SchemaException.php          # Incompatible schemas between snapshots
│
├── bin/
│   ├── merql                            # CLI entry point
│   ├── oracle                           # Compute expected merge result for scenario
│   ├── actual                           # Run merql, capture output
│   ├── compare                          # Diff oracle vs actual
│   ├── test-scenario                    # Full pipeline
│   ├── test-regression                  # Run all scenarios
│   ├── verify-compliance                # Full compliance report
│   └── verify-all                       # analyse + cs + phpunit + oracle
│
├── tests/
│   ├── Unit/
│   │   ├── Snapshot/
│   │   │   ├── SnapshotterTest.php      # Row fingerprinting, table capture
│   │   │   └── RowFingerprintTest.php   # Hash stability, collision resistance
│   │   ├── Diff/
│   │   │   ├── DifferTest.php           # Insert/update/delete detection
│   │   │   └── ColumnDiffTest.php       # Per-column change tracking
│   │   ├── Merge/
│   │   │   ├── ThreeWayMergeTest.php    # All merge rule combinations
│   │   │   ├── ColumnMergeTest.php      # Column-level merge logic
│   │   │   ├── ConflictTest.php         # Conflict detection correctness
│   │   │   └── ConflictResolverTest.php # Policy application
│   │   ├── Apply/
│   │   │   ├── SqlGeneratorTest.php     # SQL output correctness
│   │   │   └── DryRunTest.php           # Preview without side effects
│   │   ├── Identity/
│   │   │   ├── PrimaryKeyIdentityTest.php
│   │   │   └── NaturalKeyIdentityTest.php
│   │   └── Filter/
│   │       ├── TableFilterTest.php
│   │       └── ColumnFilterTest.php
│   └── Oracle/
│       ├── OracleCapture.php            # Compute expected merge from known inputs
│       ├── ActualCapture.php            # Run merql merge, capture result
│       ├── ScenarioComparator.php       # Compare expected vs actual merge
│       ├── ScenarioRunner.php           # Orchestrate scenario pipeline
│       └── ScenarioRepository.php       # Discover scenarios
│
├── scenarios/
│   ├── clean/                           # Clean merges (no conflicts)
│   │   ├── insert-only-theirs/          # Theirs inserted rows, ours unchanged
│   │   ├── update-only-theirs/          # Theirs updated rows, ours unchanged
│   │   ├── delete-only-theirs/          # Theirs deleted rows, ours unchanged
│   │   ├── insert-only-ours/            # Ours inserted rows, theirs unchanged
│   │   ├── mixed-no-overlap/            # Both changed, but different tables/rows
│   │   ├── both-same-change/            # Both made identical changes
│   │   ├── column-level-clean/          # Both changed same row, different columns
│   │   └── multi-table/                 # Changes spanning many tables
│   │
│   ├── conflict/                        # Conflict scenarios
│   │   ├── both-update-same-column/     # Both changed same column to different values
│   │   ├── update-vs-delete/            # One updated, other deleted same row
│   │   ├── delete-vs-update/            # Reverse direction
│   │   ├── both-insert-same-pk/         # Both inserted row with same primary key
│   │   ├── multiple-conflicts/          # Several conflicts in one merge
│   │   └── partial-conflict/            # Some columns conflict, others merge clean
│   │
│   ├── identity/                        # Row identity edge cases
│   │   ├── auto-increment/              # New rows have different IDs across branches
│   │   ├── natural-key/                 # Match by unique columns, not PK
│   │   ├── composite-key/               # Multi-column primary key
│   │   └── no-key/                      # Table without primary key
│   │
│   ├── types/                           # Data type handling
│   │   ├── text-columns/                # VARCHAR, TEXT, LONGTEXT
│   │   ├── numeric-columns/             # INT, DECIMAL, FLOAT
│   │   ├── date-columns/                # DATE, DATETIME, TIMESTAMP
│   │   ├── json-columns/                # JSON column merge
│   │   ├── blob-columns/                # Binary data
│   │   └── null-handling/               # NULL ↔ value transitions
│   │
│   ├── scale/                           # Performance scenarios
│   │   ├── 1k-rows/
│   │   ├── 10k-rows/
│   │   ├── 100k-rows/
│   │   └── wide-table/                  # Table with 50+ columns
│   │
│   └── edge/                            # Edge cases
│       ├── empty-changeset/             # No changes on one or both sides
│       ├── schema-mismatch/             # Column added/removed between snapshots
│       ├── encoding/                    # UTF-8, emoji, binary in text columns
│       └── large-text/                  # Very large TEXT/LONGTEXT values
│
├── composer.json
├── phpunit.xml.dist
├── phpcs.xml
└── CLAUDE.md
```

## Public API

### Facade

```php
Merql::init(PDO $pdo);                                       // Initialize with database connection
Merql::snapshot(string $name, array $tables = []);            // Capture current state
Merql::diff(string $base, string $current): Changeset;       // Compute changeset
Merql::merge(string $base, string $ours, string $theirs): MergeResult;
Merql::apply(MergeResult $result): ApplyResult;              // Execute merge
Merql::reset();                                              // Reset singletons (testing)
```

### Snapshotter

```php
$snapshotter = new Snapshotter($pdo);

// Capture full database
$snapshot = $snapshotter->capture('baseline');

// Capture specific tables
$snapshot = $snapshotter->capture('baseline', tables: ['posts', 'post_meta', 'settings']);

// Capture with filters
$snapshot = $snapshotter->capture('baseline', filters: [
    TableFilter::exclude(['sessions', 'cache_*']),
    ColumnFilter::ignore(['updated_at', 'modified_date']),
]);

// Load existing snapshot
$snapshot = SnapshotStore::load('baseline');
```

### Differ

```php
$differ = new Differ();
$changeset = $differ->diff($baseSnapshot, $currentSnapshot);

// Inspect changeset
$changeset->inserts();            // RowInsert[]
$changeset->updates();            // RowUpdate[]
$changeset->deletes();            // RowDelete[]
$changeset->isEmpty();            // bool
$changeset->count();              // total operations
$changeset->forTable('posts');    // operations for one table
```

### ThreeWayMerge

```php
$merge = new ThreeWayMerge();
$result = $merge->merge($baseSnapshot, $oursSnapshot, $theirsSnapshot);

// Inspect result
$result->isClean();               // bool — no conflicts
$result->operations();            // MergeOperation[] — resolved operations
$result->conflicts();             // Conflict[] — unresolved conflicts

// Resolve conflicts
$resolved = ConflictResolver::resolve($result, ConflictPolicy::TheirsWins);
$resolved = ConflictResolver::resolve($result, ConflictPolicy::OursWins);

// Manual conflict resolution
foreach ($result->conflicts() as $conflict) {
    echo "{$conflict->table()}.{$conflict->column()} on row {$conflict->primaryKey()}:\n";
    echo "  ours:   {$conflict->oursValue()}\n";
    echo "  theirs: {$conflict->theirsValue()}\n";
}
```

### Applier

```php
// Apply to database
$applier = new Applier($pdo);
$applyResult = $applier->apply($mergeResult);
echo "Rows affected: {$applyResult->rowsAffected()}";

// Dry run — get SQL without executing
$sql = DryRun::generate($mergeResult);
foreach ($sql as $statement) {
    echo $statement . ";\n";
}
```

## Configuration

| Constant | Default | Description |
|---|---|---|
| `MERQL_SNAPSHOT_DIR` | `.merql/snapshots` | Directory for persisted snapshots |
| `MERQL_FINGERPRINT_ALGO` | `xxh3` | Hash algorithm for row fingerprints (xxh3, sha1, md5) |
| `MERQL_COLUMN_MERGE` | `true` | Enable column-level merge (false = row-level only) |
| `MERQL_IGNORE_COLUMNS` | `[]` | Columns to ignore globally (e.g., timestamps) |
| `MERQL_MAX_SNAPSHOT_TABLES` | `500` | Maximum tables per snapshot |

## Key Rules

1. Pure PHP. No extensions beyond PDO (which is built-in everywhere). No `exec()`. No external diff tools. The merge algorithm is implemented entirely in PHP.
2. Three-way merge is the only merge strategy. Two-way diff is a building block, not a product. The library always needs three states: base, ours, theirs. Without a common base, you cannot distinguish "added" from "unchanged."
3. Column-level merge is the default. Row-level merge (flag entire row as conflicting if any column differs) is available as a fallback but column-level is the primary mode. This is the key advantage over naive approaches.
4. Row identity is determined by primary key by default. For tables without auto-increment PKs, support natural keys (unique columns) and content hash fallback. Row identity must be stable across snapshots.
5. Snapshots are fingerprint-first. Store a hash per row for fast change detection. Only fetch full row data when a change is detected. This keeps snapshots small for large tables.
6. Changesets are ordered. Operations within a table respect foreign key constraints. Inserts before updates that reference them. Deletes after updates that de-reference them. Cross-table ordering respects declared foreign keys.
7. Generated SQL uses parameterized queries. Never interpolate values into SQL strings. The applier uses prepared statements for every operation.
8. Schema changes between snapshots are detected and reported. If a column was added or removed between base and current, flag it. merql does not auto-migrate schemas — that is the caller's job.
9. NULL is a value, not an absence. `NULL → 'hello'` is an update. `'hello' → NULL` is an update. `NULL → NULL` is no change. NULL handling is the third largest source of bugs after identity resolution and type coercion.
10. Auto-increment IDs are not stable identifiers across branches. When both sides insert rows, they get different auto-increment IDs. merql must handle this: match by natural key when available, or treat as independent inserts.
11. JSON columns are merged as opaque strings by default. JSON-aware deep merge (merge object keys independently) is a future enhancement, not a v1 requirement.
12. PHP 8.2+. Use readonly classes for `Snapshot`, `Changeset`, `RowInsert`, `RowUpdate`, `RowDelete`, `Conflict`, `MergeResult`. Use enums for `ConflictPolicy`. Use match expressions.
13. Direct PDO for database access. No framework dependency. Pass a PDO instance or connection config.

## Oracle Model

Same oracle-driven verification model as pitmaster, greph, and php-browser (sibling projects in this repo).

There is no existing tool that performs three-way database merge, so the oracle is **computed from known inputs**. Each scenario has a deterministic expected result that can be verified by logic:

```
1. SETUP    → create three database states (base, ours, theirs) with known content
2. ORACLE   → compute expected merge result from the known inputs (deterministic)
3. ACTUAL   → run merql merge on the three states
4. COMPARE  → actual merge result must match expected result exactly
```

### Relationship to sibling projects

| Concept | php-browser | pitmaster | greph | merql |
|---|---|---|---|---|
| Oracle | Chromium | `git` | `grep` + `rg` + `sg` | Computed from known inputs |
| Actual | PHP renderer | Pitmaster | greph | merql |
| Pipeline | oracle → render → compare | oracle → actual → compare | oracle → actual → compare | oracle → actual → compare |
| Regression | `./bin/test-regression` | `./bin/test-regression` | `./bin/test-regression` | `./bin/test-regression` |

Study `pitmaster/tests/Oracle/` for the reference implementation of the oracle pattern.

### Scenario Structure

```
scenarios/clean/column-level-clean/
├── scenario.json                 # Metadata: name, category, tables, description
├── setup/
│   ├── schema.sql                # CREATE TABLE statements
│   ├── base.sql                  # INSERT statements for base state
│   ├── ours.sql                  # Mutations applied to base → ours
│   └── theirs.sql                # Mutations applied to base → theirs
├── oracle/
│   ├── changeset-ours.json       # Expected ours changeset
│   ├── changeset-theirs.json     # Expected theirs changeset
│   ├── merge-result.json         # Expected merge result (operations + conflicts)
│   └── applied.sql               # Expected SQL output
├── actual/
│   ├── changeset-ours.json       # merql-computed ours changeset
│   ├── changeset-theirs.json     # merql-computed theirs changeset
│   ├── merge-result.json         # merql merge result
│   └── applied.sql               # merql-generated SQL
└── reports/
    └── comparison.json           # Diff results per output
```

### scenario.json

```json
{
    "name": "column-level-clean",
    "category": "clean",
    "description": "Both sides modify same row but different columns, column-level merge resolves cleanly",
    "tables": ["test_posts"],
    "expectations": {
        "conflicts": 0,
        "operations": 3,
        "changeset_match": "exact",
        "merge_match": "exact",
        "sql_match": "semantic"
    }
}
```

### Conflict scenarios

For conflict scenarios, the oracle defines exactly which conflicts should be detected:

```json
{
    "name": "both-update-same-column",
    "category": "conflict",
    "expectations": {
        "conflicts": 1,
        "conflict_details": [
            {
                "table": "test_posts",
                "primary_key": {"id": 42},
                "column": "title",
                "ours_value": "Welcome",
                "theirs_value": "Greetings"
            }
        ]
    }
}
```

### Compliance Report

```
merql Compliance Report
=======================

Clean Merge Scenarios:      8/8 passed
  insert-only-theirs       PASS  (3 inserts applied)
  update-only-theirs       PASS  (2 updates applied)
  delete-only-theirs       PASS  (1 delete applied)
  column-level-clean       PASS  (3 columns merged independently)
  mixed-no-overlap         PASS  (5 operations across 3 tables)
  both-same-change         PASS  (identical changes, no conflict)
  multi-table              PASS  (12 operations across 5 tables)
  insert-only-ours         PASS  (2 inserts preserved)

Conflict Scenarios:         6/6 passed
  both-update-same-col     PASS  (1 conflict detected correctly)
  update-vs-delete         PASS  (1 conflict detected correctly)
  delete-vs-update         PASS  (1 conflict detected correctly)
  both-insert-same-pk      PASS  (1 conflict detected correctly)
  multiple-conflicts       PASS  (3 conflicts detected correctly)
  partial-conflict         PASS  (2 clean + 1 conflict in same row)

Identity Scenarios:         4/4 passed
  auto-increment           PASS  (natural key fallback used)
  natural-key              PASS  (unique columns matched)
  composite-key            PASS  (multi-column PK matched)
  no-key                   PASS  (content hash matched)

Type Scenarios:             6/6 passed
  text-columns             PASS  (VARCHAR, TEXT, LONGTEXT)
  numeric-columns          PASS  (INT, DECIMAL, FLOAT)
  date-columns             PASS  (DATE, DATETIME, TIMESTAMP)
  json-columns             PASS  (opaque string comparison)
  blob-columns             PASS  (binary data)
  null-handling            PASS  (NULL transitions)

Edge Cases:                 4/4 passed
  empty-changeset          PASS  (no-op merge)
  schema-mismatch          PASS  (detected and reported)
  encoding                 PASS  (UTF-8, emoji preserved)
  large-text               PASS  (LONGTEXT values)

Total: 28/28 scenarios passed
```

## Implementation Order

Build bottom-up. Each phase unlocks new scenario categories.

### Phase 1: Snapshot and diff (prove we can detect changes)

1. `TableSchema` + `SchemaReader` (read table structure from INFORMATION_SCHEMA)
2. `RowFingerprint` (hash row content for fast comparison)
3. `Snapshotter` (capture table states as fingerprints + data)
4. `SnapshotStore` (persist/load snapshots as JSON)
5. `Differ` (compare two snapshots → changeset of inserts/updates/deletes)
6. `ColumnDiff` (per-column change tracking within updates)

**Oracle gate:** `./bin/test-regression --category types` all green. Differ correctly detects inserts, updates, deletes across all data types. Column-level diffs track exactly which columns changed.

### Phase 2: Three-way merge (prove we merge correctly)

7. `ThreeWayMerge` (core algorithm: base + ours + theirs → result)
8. `ColumnMerge` (per-column merge within a row)
9. `Conflict` (represent unresolved conflicts)
10. `MergeResult` (clean operations + conflicts)
11. `ConflictPolicy` + `ConflictResolver` (ours-wins, theirs-wins, manual)

**Oracle gate:** `./bin/test-regression --category clean --category conflict` all green. All merge rules produce correct results. All conflict types detected. Column-level merge resolves cases that row-level would conflict on.

### Phase 3: Apply (prove we can execute merges)

12. `SqlGenerator` (merge result → parameterized SQL statements)
13. `DryRun` (preview SQL without executing)
14. `Applier` (execute SQL, report results)
15. Foreign key ordering (inserts before dependent updates, deletes after de-referencing)

**Oracle gate:** `./bin/test-regression --category clean` with actual database verification. Apply the merge, snapshot the result, compare against expected state.

### Phase 4: Identity and filters (prove we handle real-world tables)

16. `PrimaryKeyIdentity` (match rows by PK)
17. `NaturalKeyIdentity` (match by unique columns)
18. `ContentHashIdentity` (fallback for keyless tables)
19. `TableFilter` + `ColumnFilter` + `RowFilter`
20. Auto-increment divergence handling

**Oracle gate:** `./bin/test-regression --category identity` all green. Auto-increment IDs handled correctly. Natural keys work. Keyless tables don't crash.

### Phase 5: Hardening

21. Schema mismatch detection
22. Large table performance (100k+ rows)
23. Encoding edge cases (UTF-8, emoji, binary)
24. Linked table awareness (parent ↔ child foreign key relationships)
25. Table-specific filters (skip transient/cache tables)

**Oracle gate:** `./bin/test-regression --category edge --category scale` all green. Full compliance report clean.

## Comment Policy

Same as all inline0 packages. PHPDoc on public APIs. Inline comments explain why, not what. No decorative separators. No em dashes. Use periods, commas, colons, or rewrite.
