<?php

declare(strict_types=1);

namespace Merql\Schema;

use Merql\Database\DatabaseConnection;
use Merql\Driver\Driver;

/**
 * Reads table schemas using the appropriate database driver.
 */
final class SchemaReader
{
    public function __construct(
        private readonly DatabaseConnection $connection,
        private readonly Driver $driver,
    ) {
    }

    public function read(string $table): TableSchema
    {
        return $this->driver->readSchema($this->connection, $table);
    }

    /**
     * @return list<string>
     */
    public function listTables(): array
    {
        return $this->driver->listTables($this->connection);
    }
}
