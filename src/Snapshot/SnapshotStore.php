<?php

declare(strict_types=1);

namespace Merql\Snapshot;

use Merql\Exceptions\SnapshotException;
use Merql\Schema\TableSchema;

/**
 * Persist and load snapshots as JSON files.
 */
final class SnapshotStore
{
    private static string $directory = '.merql/snapshots';

    public static function setDirectory(string $directory): void
    {
        self::$directory = $directory;
    }

    public static function getDirectory(): string
    {
        return self::$directory;
    }

    public static function save(Snapshot $snapshot): void
    {
        self::validateName($snapshot->name);
        $dir = self::$directory;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $data = self::serialize($snapshot);
        $path = $dir . '/' . $snapshot->name . '.json';
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public static function load(string $name): Snapshot
    {
        self::validateName($name);
        $path = self::$directory . '/' . $name . '.json';
        if (!file_exists($path)) {
            throw SnapshotException::notFound($name, $path);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw SnapshotException::notFound($name, $path);
        }

        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new SnapshotException("Snapshot '{$name}' is malformed: expected object at root.");
        }

        return self::deserialize($name, $data);
    }

    public static function exists(string $name): bool
    {
        self::validateName($name);

        return file_exists(self::$directory . '/' . $name . '.json');
    }

    public static function delete(string $name): void
    {
        self::validateName($name);
        $path = self::$directory . '/' . $name . '.json';
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Validate snapshot name contains only safe characters.
     */
    private static function validateName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) {
            throw new SnapshotException(
                "Invalid snapshot name '{$name}': "
                . 'only alphanumeric, dash, and underscore allowed',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function serialize(Snapshot $snapshot): array
    {
        $tables = [];
        foreach ($snapshot->tables as $name => $tableSnapshot) {
            $tables[$name] = [
                'schema' => [
                    'name' => $tableSnapshot->schema->name,
                    'columns' => $tableSnapshot->schema->columns,
                    'primaryKey' => $tableSnapshot->schema->primaryKey,
                    'uniqueKeys' => $tableSnapshot->schema->uniqueKeys,
                ],
                'fingerprints' => $tableSnapshot->fingerprints,
                'rows' => $tableSnapshot->rows,
                'identityColumns' => $tableSnapshot->identityColumns,
            ];
        }

        return ['tables' => $tables];
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private static function deserialize(string $name, array $data): Snapshot
    {
        $tablesData = $data['tables'] ?? null;
        if (!is_array($tablesData)) {
            throw new SnapshotException("Snapshot '{$name}' is malformed: missing 'tables'.");
        }

        $tables = [];
        foreach ($tablesData as $tableName => $tableData) {
            if (!is_string($tableName) || !is_array($tableData)) {
                continue;
            }
            $tables[$tableName] = self::deserializeTable($name, $tableName, $tableData);
        }

        return new Snapshot($name, $tables);
    }

    /**
     * @param array<int|string, mixed> $tableData
     */
    private static function deserializeTable(string $snapshot, string $tableName, array $tableData): TableSnapshot
    {
        $schemaData = $tableData['schema'] ?? null;
        if (!is_array($schemaData)) {
            throw new SnapshotException(
                "Snapshot '{$snapshot}' table '{$tableName}' is malformed: missing 'schema'.",
            );
        }

        $schemaName = $schemaData['name'] ?? null;
        $schemaColumns = $schemaData['columns'] ?? null;
        $schemaPrimaryKey = $schemaData['primaryKey'] ?? null;
        $schemaUniqueKeys = $schemaData['uniqueKeys'] ?? null;

        if (
            !is_string($schemaName)
            || !is_array($schemaColumns)
            || !is_array($schemaPrimaryKey)
            || !is_array($schemaUniqueKeys)
        ) {
            throw new SnapshotException(
                "Snapshot '{$snapshot}' table '{$tableName}' has invalid schema fields.",
            );
        }

        $schema = new TableSchema(
            $schemaName,
            self::stringMap($schemaColumns),
            self::stringList($schemaPrimaryKey),
            self::stringListList($schemaUniqueKeys),
        );

        $fingerprints = $tableData['fingerprints'] ?? null;
        $rows = $tableData['rows'] ?? null;
        $identityColumns = $tableData['identityColumns'] ?? null;

        if (!is_array($fingerprints) || !is_array($rows) || !is_array($identityColumns)) {
            throw new SnapshotException(
                "Snapshot '{$snapshot}' table '{$tableName}' has invalid row data.",
            );
        }

        return new TableSnapshot(
            $schema,
            self::fingerprintMap($fingerprints),
            self::rowMap($rows),
            self::stringList($identityColumns),
        );
    }

    /**
     * @param array<int|string, mixed> $raw
     * @return array<string, string>
     */
    private static function stringMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $raw
     * @return list<string>
     */
    private static function stringList(array $raw): array
    {
        $out = [];
        foreach ($raw as $v) {
            if (is_string($v)) {
                $out[] = $v;
            }
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $raw
     * @return list<list<string>>
     */
    private static function stringListList(array $raw): array
    {
        $out = [];
        foreach ($raw as $inner) {
            if (is_array($inner)) {
                $out[] = self::stringList($inner);
            }
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $raw
     * @return array<int|string, string>
     */
    private static function fingerprintMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $v) {
            if (is_string($v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $raw
     * @return array<int|string, array<string, scalar|null>>
     */
    private static function rowMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowOut = [];
            foreach ($row as $col => $val) {
                if (!is_string($col)) {
                    continue;
                }
                $rowOut[$col] = is_scalar($val) || $val === null ? $val : null;
            }
            $out[$k] = $rowOut;
        }

        return $out;
    }
}
