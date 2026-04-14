# Changelog

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
