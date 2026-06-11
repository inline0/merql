# Changelog

## [0.4.0] - 2026-06-11

### Added

- `DatabaseConnection` abstraction covering query execution, positional
  prepared-statement parameters, scalar reads, transactions, last insert IDs,
  and driver identity.
- PDO and mysqli connection adapters with matching string-or-null fetch shapes.
- `Connection::mysqli()` and `Connection::fromMysqli()` for MySQL access on
  hosts where `pdo_mysql` is unavailable.
- Adapter contract tests and a mysqli/MySQL integration lane covering
  stringified values, transactions, binding edge cases, surfaced errors, and a
  snapshot-to-rollback merge flow.

### Changed

- `Merql::init()`, `Snapshotter`, appliers, schema readers, and driver
  detection now accept `DatabaseConnection` instead of raw PDO.
- `Connection::mysql()`, `Connection::sqlite()`, and `Connection::fromDsn()`
  now return PDO adapter instances instead of raw PDO handles.
- Driver detection now keys off `DatabaseConnection::driverName()` instead of
  `PDO::ATTR_DRIVER_NAME`.
- `ext-pdo` is no longer a hard runtime package requirement; install the
  extension needed by the adapter in use.

## [0.3.0] - 2026-06-06

### Added

- UI-ready merge plans with stable operation IDs, change group IDs, summaries,
  conflict payloads, metadata, hashes, and JSON serialization.
- Change group and operation selections for staging a subset of merge
  operations before apply.
- Selected merge result generation for applying only reviewed operations from a
  merge plan.
- Rollback plan generation, rollback JSON serialization, drift detection, and
  inverse operation apply.
- Guarded SQL generation and guarded transactional apply with optimistic
  live-row preconditions.
- `Snapshotter::captureAliased()` for capturing physical table names under
  canonical names.
- `IdentityRule`, `IdentityRuleSet`, and `IdentityConflict` for explicit table
  identity rules and ambiguous identity detection.
- Public documentation for merge plans, identity rules, selected apply,
  guarded apply, rollback artifacts, and aliased snapshots.
- Merge primitive coverage verification for plan, rollback, guarded apply, and
  identity-rule code paths.

### Changed

- `Snapshotter` now accepts an optional `IdentityRuleSet` and rejects ambiguous
  captured row identity keys instead of overwriting rows.
- `Merql::init()` now accepts optional driver and identity rule arguments.
- README examples now cover staged merge plans, guarded apply, rollback
  artifacts, identity rules, and canonical table capture.

## [0.2.0] - 2026-05-19

### Changed

- PHPStan level raised from 8 to 10. Row data is now typed as `array<string, scalar|null>` end-to-end, matching what `PDO::FETCH_ASSOC` actually returns.
- `CellMerger::merge()` signature narrowed from `mixed` to `string|int|float|bool|null` for `$base`, `$ours`, `$theirs`. **Breaking** for custom `CellMerger` implementations.
- `CellMergeResult::resolved()` / `::conflict()` and constructor narrowed `mixed` to `string|int|float|bool|null`.
- `ColumnDiff` constructor narrowed `mixed` values to `string|int|float|bool|null`.
- `Conflict` value parameters and accessors narrowed to `scalar|null|array<string, scalar|null>`.
- PDO boundaries (`MysqlDriver`, `SqliteDriver`, `DriverFactory`) now validate row shapes at runtime instead of blind-casting.
- `SnapshotStore::deserialize()` validates JSON structure and per-field types before constructing snapshots.
- PHPUnit bumped to ^13.1 with the 13.x config schema.

## [0.1.0] - 2026-04-14

### Added

- Three-way database merge with column-level conflict resolution
- Cell-level merge for TEXT (line-by-line via pitmaster Myers diff) and JSON (key-by-key) columns
- Pluggable database driver system with MySQL and SQLite built in
- Snapshot capture with row fingerprinting for fast change detection
- Differ computes insert, update, and delete changesets between two snapshots
- Per-column change tracking within row updates
- Conflict detection for update/update, update/delete, delete/update, and insert/insert
- ConflictResolver with OursWins, TheirsWins, and Manual policies
- SQL generation with parameterized queries and FK-aware ordering
- Dry-run preview of generated SQL
- Applier executes merge results in a database transaction
- Row identity: primary key, natural key, content hash strategies
- Table, column, and row filters for snapshot and merge
- Schema mismatch detection across snapshots
- Snapshot persistence as JSON with path traversal protection
- Two-way merge via patch() shortcut
- CLI for snapshot, diff, and merge operations
- 195 tests (179 unit + 16 integration), 420 assertions
- 32 oracle regression scenarios across 6 categories
- PHPStan level 8, PSR-12 coding standards
