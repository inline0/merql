<?php

declare(strict_types=1);

namespace Merql\Snapshot;

use Merql\Driver\Driver;
use Merql\Driver\DriverFactory;
use Merql\Filter\ColumnFilter;
use Merql\Filter\RowFilter;
use Merql\Filter\TableFilter;
use Merql\Schema\PrimaryKeyResolver;
use Merql\Schema\SchemaReader;
use PDO;

/**
 * Captures database state as a snapshot.
 */
final class Snapshotter
{
    private readonly SchemaReader $schemaReader;
    private readonly Driver $driver;

    public function __construct(
        private readonly PDO $pdo,
        ?Driver $driver = null,
    ) {
        $this->driver = $driver ?? DriverFactory::create($pdo);
        $this->schemaReader = new SchemaReader($pdo, $this->driver);
    }

    /**
     * Capture current database state.
     *
     * @param list<string> $tables Specific tables to capture (empty = all).
     * @param list<TableFilter|ColumnFilter|RowFilter> $filters Filters to apply.
     */
    public function capture(string $name, array $tables = [], array $filters = []): Snapshot
    {
        $tableNames = $tables !== [] ? $tables : $this->schemaReader->listTables();
        $tableFilter = $this->findFilter($filters, TableFilter::class);

        if ($tableFilter !== null) {
            $tableNames = $tableFilter->apply($tableNames);
        }

        $columnFilter = $this->findFilter($filters, ColumnFilter::class);
        $rowFilter = $this->findFilter($filters, RowFilter::class);

        $tableSnapshots = [];
        foreach ($tableNames as $tableName) {
            $tableSnapshots[$tableName] = $this->captureTable(
                $tableName,
                $columnFilter,
                $rowFilter,
            );
        }

        return new Snapshot($name, $tableSnapshots);
    }

    /**
     * Build a snapshot from raw data without a database connection.
     *
     * @param array<string, TableSnapshotData> $tableData Table name to data mapping.
     */
    public static function fromData(string $name, array $tableData): Snapshot
    {
        $tables = [];
        foreach ($tableData as $tableName => $data) {
            $tables[$tableName] = self::buildTableSnapshot($data);
        }

        return new Snapshot($name, $tables);
    }

    private function captureTable(
        string $tableName,
        ?ColumnFilter $columnFilter,
        ?RowFilter $rowFilter,
    ): TableSnapshot {
        $schema = $this->schemaReader->read($tableName);
        $identityColumns = PrimaryKeyResolver::resolve($schema);

        $stmt = $this->pdo->query($this->driver->selectAll($tableName));
        $allRows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $fingerprints = [];
        $rows = [];

        foreach ($allRows as $row) {
            if ($rowFilter !== null && !$rowFilter->shouldInclude($tableName, $row)) {
                continue;
            }

            // Build row key from unfiltered row to preserve identity columns.
            $key = self::buildRowKey($row, $identityColumns);

            if ($columnFilter !== null) {
                $row = $columnFilter->applyToRow($row);
            }

            $fingerprints[$key] = RowFingerprint::compute($row);
            $rows[$key] = $row;
        }

        return new TableSnapshot($schema, $fingerprints, $rows, $identityColumns);
    }

    private static function buildTableSnapshot(TableSnapshotData $data): TableSnapshot
    {
        $fingerprints = [];
        $rows = [];

        foreach ($data->rows as $row) {
            $key = self::buildRowKey($row, $data->identityColumns);
            $fingerprints[$key] = RowFingerprint::compute($row);
            $rows[$key] = $row;
        }

        return new TableSnapshot($data->schema, $fingerprints, $rows, $data->identityColumns);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $identityColumns
     */
    public static function buildRowKey(array $row, array $identityColumns): string
    {
        $parts = [];
        foreach ($identityColumns as $col) {
            $val = $row[$col] ?? '';
            // Escape separator and escape char to prevent collisions.
            $parts[] = str_replace(
                ['%', "\x1F"],
                ['%25', '%1F'],
                (string) $val,
            );
        }

        return implode("\x1F", $parts);
    }

    /**
     * Decode a row key back into its component parts.
     *
     * @return list<string>
     */
    public static function decodeRowKey(string $key): array
    {
        $parts = explode("\x1F", $key);

        return array_map(
            fn(string $part) => str_replace(['%1F', '%25'], ["\x1F", '%'], $part),
            $parts,
        );
    }

    /**
     * @template T
     * @param list<mixed> $filters
     * @param class-string<T> $class
     * @return T|null
     */
    private function findFilter(array $filters, string $class): mixed
    {
        foreach ($filters as $filter) {
            if ($filter instanceof $class) {
                return $filter;
            }
        }

        return null;
    }
}
