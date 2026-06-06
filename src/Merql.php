<?php

declare(strict_types=1);

namespace Merql;

use Merql\Apply\Applier;
use Merql\Apply\ApplyResult;
use Merql\Apply\GuardedApplier;
use Merql\Diff\Changeset;
use Merql\Diff\Differ;
use Merql\Driver\Driver;
use Merql\Identity\IdentityRuleSet;
use Merql\Merge\MergeResult;
use Merql\Plan\MergePlan;
use Merql\Plan\MergePlanBuilder;
use Merql\Merge\ThreeWayMerge;
use Merql\Snapshot\Snapshot;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\SnapshotStore;
use PDO;

/**
 * Static facade: public API entry point.
 */
final class Merql
{
    private static ?PDO $pdo = null;
    private static ?Snapshotter $snapshotter = null;

    public static function init(PDO $pdo, ?Driver $driver = null, ?IdentityRuleSet $identityRules = null): void
    {
        self::$pdo = $pdo;
        self::$snapshotter = new Snapshotter($pdo, $driver, $identityRules);
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
    public static function merge(string $base, string $ours, string $theirs): MergeResult
    {
        $baseSnapshot = SnapshotStore::load($base);
        $oursSnapshot = SnapshotStore::load($ours);
        $theirsSnapshot = SnapshotStore::load($theirs);

        $merge = new ThreeWayMerge();

        return $merge->merge($baseSnapshot, $oursSnapshot, $theirsSnapshot);
    }

    /**
     * Two-way merge: apply changes from another snapshot onto base.
     * Equivalent to merge(base, base, changes) - ours is unchanged.
     */
    public static function patch(string $base, string $changes): MergeResult
    {
        $baseSnapshot = SnapshotStore::load($base);
        $changesSnapshot = SnapshotStore::load($changes);

        $merge = new ThreeWayMerge();

        return $merge->merge($baseSnapshot, $baseSnapshot, $changesSnapshot);
    }

    /**
     * Apply a merge result to the database.
     */
    public static function apply(MergeResult $result): ApplyResult
    {
        self::requireInit();
        assert(self::$pdo !== null);

        $applier = new Applier(self::$pdo);

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
    ): MergePlan {
        $baseSnapshot = SnapshotStore::load($base);
        $oursSnapshot = SnapshotStore::load($ours);
        $theirsSnapshot = SnapshotStore::load($theirs);

        $merge = new ThreeWayMerge();
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
        assert(self::$pdo !== null);

        return (new GuardedApplier(self::$pdo))->apply($result, $expectedLive);
    }

    /**
     * Reset singletons (for testing).
     */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$snapshotter = null;
    }

    private static function requireInit(): void
    {
        if (self::$pdo === null) {
            throw new \RuntimeException('Call Merql::init() with a PDO instance first');
        }
    }
}
