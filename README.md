# merql

Pure PHP three-way database merge with column-level conflict resolution. Git-style merge semantics applied to MySQL and SQLite tables. No extensions, no external tools.

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
$base = Merql::snapshot('base');
// ... ours makes changes ...
$ours = Merql::snapshot('ours');
// ... theirs makes changes ...
$theirs = Merql::snapshot('theirs');

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

merql compares two branches against a common base and merges them at four levels of granularity:

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

## Features

- **Three-way merge** with base, ours, and theirs snapshots
- **Column-level conflict resolution** that resolves cases row-level merge would flag
- **Cell-level merge** for TEXT columns (line-by-line via Myers diff) and JSON columns (key-by-key)
- **MySQL and SQLite** via pluggable driver system, extensible to any PDO database
- **Conflict detection** with table, row, and column precision
- **Parameterized SQL generation** with FK-aware ordering
- **Dry-run preview** before applying changes
- **Transaction safety** with automatic rollback on errors
- **Row identity** via primary key, natural key, or content hash
- **Snapshot persistence** with row fingerprinting for fast change detection
- **Schema mismatch detection** across snapshots
- **Table, column, and row filters** for selective merging

## Requirements

- PHP 8.2 or later
- `ext-pdo` (built-in on virtually every PHP install)

## Documentation

Full documentation at [merql.dev](https://merql.dev) (or in the `docs/` directory).

- [Getting Started](https://merql.dev/docs/getting-started)
- [CLI Reference](https://merql.dev/docs/cli)
- [PHP API](https://merql.dev/docs/api)
- [Three-Way Merge](https://merql.dev/docs/merge/three-way)
- [Column-Level Merge](https://merql.dev/docs/merge/column-level)
- [Cell-Level Merge](https://merql.dev/docs/merge/cell-level)
- [Database Drivers](https://merql.dev/docs/apply/drivers)

## Testing

```bash
composer test:unit          # 179 unit tests
composer test:integration   # 16 integration tests (real SQLite)
composer test               # Full PHPUnit suite
composer analyse            # PHPStan level 8
composer cs                 # PSR-12 coding standards
./bin/verify-all            # All of the above + 32 oracle scenarios
```

## License

MIT. See [LICENSE](LICENSE).
