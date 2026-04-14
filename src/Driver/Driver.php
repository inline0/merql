<?php

declare(strict_types=1);

namespace Merql\Driver;

use Merql\Schema\TableSchema;
use PDO;

/**
 * Database driver interface. Abstracts all DB-engine-specific operations.
 *
 * Implement this for any PDO-supported database engine.
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
    public function listTables(PDO $pdo): array;

    /**
     * Read full schema for a table.
     */
    public function readSchema(PDO $pdo, string $table): TableSchema;

    /**
     * Read foreign key dependencies.
     *
     * @return array<string, list<string>> Child table to list of parent tables.
     */
    public function readForeignKeys(PDO $pdo): array;

    /**
     * Build a SELECT * query for a table.
     */
    public function selectAll(string $table): string;
}
