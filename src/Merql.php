<?php

declare(strict_types=1);

namespace Merql;

use Merql\Apply\Applier;
use Merql\Apply\ApplyResult;
use Merql\Apply\GuardedApplier;
use Merql\Database\DatabaseConnection;
use Merql\Diff\Changeset;
use Merql\Diff\Differ;
use Merql\Driver\Driver;
use Merql\Identity\IdentityRuleSet;
use Merql\Merge\MergeResult;
use Merql\CellMerge\CellMergeConfig;
use Merql\Plan\MergePlan;
use Merql\Plan\MergePlanBuilder;
use Merql\Merge\ThreeWayMerge;
use Merql\Snapshot\Snapshot;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\SnapshotStore;

/**
 * Static facade: public API entry point.
 */
final class Merql
{
    private static ?DatabaseConnection $connection = null;
    private static ?Snapshotter $snapshotter = null;
    private static ?CellMergeConfig $cellMergeConfig = null;

    public static function init(
        DatabaseConnection $connection,
        ?Driver $driver = null,
        ?IdentityRuleSet $identityRules = null,
        ?CellMergeConfig $cellMergeConfig = null,
    ): void {
        self::$connection = $connection;
        self::$snapshotter = new Snapshotter($connection, $driver, $identityRules);
        self::$cellMergeConfig = $cellMergeConfig;
    }

    /**
     * Capture current database state.
     *
     * @param list<string> $tables Specific tables (empty = all).
     */
    public static function snapshot(string $name, array $tables = []): Snapshot
    {
        self::requireInit();
        assert(self::$snapshotter !== null);

        $snapshot = self::$snapshotter->capture($name, $tables);
        SnapshotStore::save($snapshot);

        return $snapshot;
    }

    /**
     * Compute changeset between two named snapshots.
     */
    public static function diff(string $base, string $current): Changeset
    {
        $baseSnapshot = SnapshotStore::load($base);
        $currentSnapshot = SnapshotStore::load($current);

        $differ = new Differ();

        return $differ->diff($baseSnapshot, $currentSnapshot);
    }

    /**
     * Three-way merge of named snapshots.
     */
    public static function merge(
        string $base,
        string $ours,
        string $theirs,
        ?CellMergeConfig $cellMergeConfig = null,
    ): MergeResult {
        $baseSnapshot = SnapshotStore::load($base);
        $oursSnapshot = SnapshotStore::load($ours);
        $theirsSnapshot = SnapshotStore::load($theirs);

        $merge = new ThreeWayMerge($cellMergeConfig ?? self::$cellMergeConfig);

        return $merge->merge($baseSnapshot, $oursSnapshot, $theirsSnapshot);
    }

    /**
     * Two-way merge: apply changes from another snapshot onto base.
     * Equivalent to merge(base, base, changes) - ours is unchanged.
     */
    public static function patch(string $base, string $changes, ?CellMergeConfig $cellMergeConfig = null): MergeResult
    {
        $baseSnapshot = SnapshotStore::load($base);
        $changesSnapshot = SnapshotStore::load($changes);

        $merge = new ThreeWayMerge($cellMergeConfig ?? self::$cellMergeConfig);

        return $merge->merge($baseSnapshot, $baseSnapshot, $changesSnapshot);
    }

    /**
     * Apply a merge result to the database.
     */
    public static function apply(MergeResult $result): ApplyResult
    {
        self::requireInit();
        assert(self::$connection !== null);

        $applier = new Applier(self::$connection);

        return $applier->apply($result);
    }

    /**
     * Build a merge plan from named snapshots.
     *
     * @param array<string, mixed> $metadata
     */
    public static function plan(
        string $id,
        string $base,
        string $ours,
        string $theirs,
        array $metadata = [],
        ?CellMergeConfig $cellMergeConfig = null,
    ): MergePlan {
        $baseSnapshot = SnapshotStore::load($base);
        $oursSnapshot = SnapshotStore::load($ours);
        $theirsSnapshot = SnapshotStore::load($theirs);

        $merge = new ThreeWayMerge($cellMergeConfig ?? self::$cellMergeConfig);
        $result = $merge->merge($baseSnapshot, $oursSnapshot, $theirsSnapshot);

        return (new MergePlanBuilder())->build(
            $id,
            $baseSnapshot,
            $oursSnapshot,
            $theirsSnapshot,
            $result,
            $metadata,
        );
    }

    /**
     * Apply a merge result with optimistic live-row preconditions.
     */
    public static function applyGuarded(MergeResult $result, Snapshot $expectedLive): ApplyResult
    {
        self::requireInit();
        assert(self::$connection !== null);

        return (new GuardedApplier(self::$connection))->apply($result, $expectedLive);
    }

    /**
     * Reset singletons (for testing).
     */
    public static function reset(): void
    {
        self::$connection = null;
        self::$snapshotter = null;
        self::$cellMergeConfig = null;
    }

    private static function requireInit(): void
    {
        if (self::$connection === null) {
            throw new \RuntimeException('Call Merql::init() with a database connection first');
        }
    }
}
