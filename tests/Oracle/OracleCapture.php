<?php

declare(strict_types=1);

namespace Merql\Tests\Oracle;

use Merql\Diff\Changeset;
use Merql\Diff\Differ;
use Merql\Merge\MergeResult;
use Merql\Merge\ThreeWayMerge;
use Merql\Schema\TableSchema;
use Merql\Snapshot\Snapshot;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\TableSnapshotData;

/**
 * Computes the expected merge result from known scenario inputs.
 *
 * The oracle builds snapshots from the setup SQL files, then computes
 * changesets and merge results deterministically.
 */
final class OracleCapture
{
    /**
     * Compute oracle result for a scenario.
     *
     * @param array{name: string, category: string, path: string, config: array<string, mixed>} $scenario
     * @return array{
     *     oursChangeset: Changeset,
     *     theirsChangeset: Changeset,
     *     mergeResult: MergeResult,
     *     base: Snapshot,
     *     ours: Snapshot,
     *     theirs: Snapshot,
     * }
     */
    public static function compute(array $scenario): array
    {
        $path = $scenario['path'];

        $base = self::loadSnapshot($path . '/setup/base.json', 'base');
        $ours = self::loadSnapshot($path . '/setup/ours.json', 'ours');
        $theirs = self::loadSnapshot($path . '/setup/theirs.json', 'theirs');

        $differ = new Differ();
        $oursChangeset = $differ->diff($base, $ours);
        $theirsChangeset = $differ->diff($base, $theirs);

        $merge = new ThreeWayMerge();
        $mergeResult = $merge->merge($base, $ours, $theirs);

        return [
            'oursChangeset' => $oursChangeset,
            'theirsChangeset' => $theirsChangeset,
            'mergeResult' => $mergeResult,
            'base' => $base,
            'ours' => $ours,
            'theirs' => $theirs,
        ];
    }

    /**
     * Load a snapshot from a JSON file containing table data.
     */
    private static function loadSnapshot(string $path, string $name): Snapshot
    {
        $data = json_decode(
            (string) file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $tables = [];
        foreach ($data['tables'] as $tableName => $tableData) {
            $schema = new TableSchema(
                $tableName,
                $tableData['columns'] ?? [],
                $tableData['primaryKey'] ?? [],
                $tableData['uniqueKeys'] ?? [],
            );

            $identityColumns = $tableData['primaryKey'] ?? array_keys($tableData['columns'] ?? []);

            $tables[$tableName] = new TableSnapshotData(
                $schema,
                $tableData['rows'] ?? [],
                $identityColumns,
            );
        }

        return Snapshotter::fromData($name, $tables);
    }
}
