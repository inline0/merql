<?php

declare(strict_types=1);

namespace Merql\Driver;

use Merql\Database\DatabaseConnection;
use Merql\Schema\TableSchema;

/**
 * Database driver interface. Abstracts all DB-engine-specific operations.
 */
interface Driver
{
    /**
     * Quote an identifier (table or column name).
     */
    public function quoteIdentifier(string $name): string;

    /**
     * List all user tables in the current database.
     *
     * @return list<string>
     */
    public function listTables(DatabaseConnection $connection): array;

    /**
     * Read full schema for a table.
     */
    public function readSchema(DatabaseConnection $connection, string $table): TableSchema;

    /**
     * Read foreign key dependencies.
     *
     * @return array<string, list<string>> Child table to list of parent tables.
     */
    public function readForeignKeys(DatabaseConnection $connection): array;

    /**
     * Build a SELECT * query for a table.
     */
    public function selectAll(string $table): string;
}
