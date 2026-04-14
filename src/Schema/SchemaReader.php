<?php

declare(strict_types=1);

namespace Merql\Schema;

use Merql\Driver\Driver;
use PDO;

/**
 * Reads table schemas using the appropriate database driver.
 */
final class SchemaReader
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Driver $driver,
    ) {
    }

    public function read(string $table): TableSchema
    {
        return $this->driver->readSchema($this->pdo, $table);
    }

    /**
     * @return list<string>
     */
    public function listTables(): array
    {
        return $this->driver->listTables($this->pdo);
    }
}
