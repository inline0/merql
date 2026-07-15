---
title: "Database Drivers"
description: "Pluggable driver system for MySQL, SQLite, and custom databases."
path: "apply/drivers"
order: 13
section: "Apply"
meta_title: "Database Drivers"
meta_description: "Pluggable driver system for MySQL, SQLite, and custom databases."
---

# Database Drivers

merql separates connection transport from database dialect. A
`DatabaseConnection` adapter executes SQL over PDO or mysqli, while a `Driver`
handles database-engine-specific metadata SQL and identifier quoting. Built-in
drivers are provided for MySQL and SQLite.

```php
use Merql\Driver\DriverFactory;

$driver = DriverFactory::create($connection);
// Auto-detects MySQL or SQLite from the connection driver identity.
```

## The Driver interface

Every driver implements five methods:

```php
use Merql\Driver\Driver;

interface Driver
{
    public function quoteIdentifier(string $name): string;
    public function listTables(DatabaseConnection $connection): array;
    public function readSchema(DatabaseConnection $connection, string $table): TableSchema;
    public function readForeignKeys(DatabaseConnection $connection): array;
    public function selectAll(string $table): string;
}
```

| Method | Purpose |
|---|---|
| `quoteIdentifier` | Quote a table or column name for use in SQL. |
| `listTables` | Return all user tables in the current database. |
| `readSchema` | Read column names, types, primary key, and unique keys for a table. |
| `readForeignKeys` | Return a map of child table to parent table dependencies. |
| `selectAll` | Build a `SELECT *` query for a table. |

## MysqlDriver

Uses `INFORMATION_SCHEMA` queries for metadata and backtick quoting for identifiers.

```php
use Merql\Driver\MysqlDriver;

$driver = new MysqlDriver();

$driver->quoteIdentifier('posts');
// `posts`

$driver->quoteIdentifier('my`table');
// `my``table`

$tables = $driver->listTables($connection);
// ['comments', 'posts', 'users']

$schema = $driver->readSchema($connection, 'posts');
// TableSchema with columns, primaryKey, uniqueKeys

$fks = $driver->readForeignKeys($connection);
// ['comments' => ['posts', 'users']]
```

Table listing queries `INFORMATION_SCHEMA.TABLES` filtered by the current database and `BASE TABLE` type. Schema reading queries `INFORMATION_SCHEMA.COLUMNS` for columns and `INFORMATION_SCHEMA.KEY_COLUMN_USAGE` for primary and unique keys.

## SqliteDriver

Uses `PRAGMA` queries for metadata and double-quote quoting for identifiers.

```php
use Merql\Driver\SqliteDriver;

$driver = new SqliteDriver();

$driver->quoteIdentifier('posts');
// "posts"

$driver->quoteIdentifier('my"table');
// "my""table"

$tables = $driver->listTables($connection);
// Queries sqlite_master, excludes sqlite_* internal tables.

$schema = $driver->readSchema($connection, 'posts');
// Uses PRAGMA table_info() and PRAGMA index_list().

$fks = $driver->readForeignKeys($connection);
// Uses PRAGMA foreign_key_list() for each table.
```

## DriverFactory

`DriverFactory` auto-detects the correct driver from `DatabaseConnection::driverName()`.

```php
use Merql\Connection;
use Merql\Driver\DriverFactory;

$connection = Connection::mysql('127.0.0.1', 'mydb', 'root', '');
$driver = DriverFactory::create($connection);
// Returns MysqlDriver

$connection = Connection::sqlite(':memory:');
$driver = DriverFactory::create($connection);
// Returns SqliteDriver
```

If the connection driver identity is not recognized, a `RuntimeException` is thrown.

### Registering custom drivers

For databases beyond MySQL and SQLite, register a custom driver class:

```php
use Merql\Driver\DriverFactory;

DriverFactory::register('pgsql', PostgresDriver::class);

$connection = Connection::fromDsn('pgsql:host=localhost;dbname=mydb', 'user', 'pass');
$driver = DriverFactory::create($connection);
// Returns your PostgresDriver instance.
```

The driver class must implement the `Driver` interface. The first argument to `register()` is the connection driver identity.

### Reset

For testing, reset the factory to its default state:

```php
DriverFactory::reset();
// Reverts to only MySQL and SQLite drivers.
```

## Connection helper

The `Connection` class builds adapters with sensible defaults. PDO adapters use
exceptions on error, associative fetch mode, real prepared statements, and
stringified fetches. The mysqli adapter sets `utf8mb4`, uses mysqli exception
mode, prepared statements, and normalizes fetched values to the same
string-or-null shape.

```php
use Merql\Connection;

// MySQL.
$connection = Connection::mysql('127.0.0.1', 'my_database', 'root', 'password');
$connection = Connection::mysql('db.example.com', 'mydb', 'user', 'pass', port: 3307);

// MySQLi.
$connection = Connection::mysqli('127.0.0.1', 'my_database', 'root', 'password');

// SQLite.
$connection = Connection::sqlite(':memory:');
$connection = Connection::sqlite('/var/data/app.sqlite');

// Any DSN.
$connection = Connection::fromDsn('pgsql:host=localhost;dbname=mydb', 'user', 'pass');
```

`Connection::sqlite()` also enables `PRAGMA foreign_keys = ON` automatically, which SQLite requires for foreign key enforcement.

## Where drivers are used

Drivers are used throughout merql:

- **Snapshotter** uses `listTables`, `readSchema`, and `selectAll` to capture database state.
- **Applier** uses `readForeignKeys` to determine operation ordering and passes the driver to `SqlGenerator` for identifier quoting.
- **SqlGenerator** uses `quoteIdentifier` to build safe SQL.

If you pass a connection to `Snapshotter` or `Applier` without specifying a driver, `DriverFactory::create()` is called automatically.
