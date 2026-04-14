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
        $path = self::$directory . '/' . $name . '.json';
        if (!file_exists($path)) {
            throw SnapshotException::notFound($name, $path);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw SnapshotException::notFound($name, $path);
        }

        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return self::deserialize($name, $data);
    }

    public static function exists(string $name): bool
    {
        return file_exists(self::$directory . '/' . $name . '.json');
    }

    public static function delete(string $name): void
    {
        $path = self::$directory . '/' . $name . '.json';
        if (file_exists($path)) {
            unlink($path);
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
     * @param array<string, mixed> $data
     */
    private static function deserialize(string $name, array $data): Snapshot
    {
        $tables = [];
        foreach ($data['tables'] as $tableName => $tableData) {
            $schema = new TableSchema(
                $tableData['schema']['name'],
                $tableData['schema']['columns'],
                $tableData['schema']['primaryKey'],
                $tableData['schema']['uniqueKeys'],
            );

            $tables[$tableName] = new TableSnapshot(
                $schema,
                $tableData['fingerprints'],
                $tableData['rows'],
                $tableData['identityColumns'],
            );
        }

        return new Snapshot($name, $tables);
    }
}
