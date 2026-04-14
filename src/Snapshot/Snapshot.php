<?php

declare(strict_types=1);

namespace Merql\Snapshot;

/**
 * A complete database snapshot: table name to TableSnapshot mapping.
 */
final readonly class Snapshot
{
    /**
     * @param string $name Snapshot identifier.
     * @param array<string, TableSnapshot> $tables Table name to snapshot mapping.
     */
    public function __construct(
        public string $name,
        public array $tables,
    ) {
    }

    public function hasTable(string $name): bool
    {
        return isset($this->tables[$name]);
    }

    public function getTable(string $name): ?TableSnapshot
    {
        return $this->tables[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function tableNames(): array
    {
        return array_keys($this->tables);
    }
}
