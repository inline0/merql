<?php

declare(strict_types=1);

namespace Merql\Merge;

use Merql\Diff\Changeset;
use Merql\Diff\Differ;
use Merql\Diff\RowDelete;
use Merql\Diff\RowInsert;
use Merql\Diff\RowUpdate;
use Merql\Snapshot\Snapshot;

/**
 * Core three-way merge algorithm.
 *
 * Takes base + ours + theirs snapshots, computes changesets,
 * and merges them into a result with conflict detection.
 */
final class ThreeWayMerge
{
    public function merge(Snapshot $base, Snapshot $ours, Snapshot $theirs): MergeResult
    {
        $differ = new Differ();
        $oursChangeset = $differ->diff($base, $ours);
        $theirsChangeset = $differ->diff($base, $theirs);

        return $this->mergeChangesets($base, $ours, $theirs, $oursChangeset, $theirsChangeset);
    }

    private function mergeChangesets(
        Snapshot $base,
        Snapshot $ours,
        Snapshot $theirs,
        Changeset $oursChangeset,
        Changeset $theirsChangeset,
    ): MergeResult {
        $operations = [];
        $conflicts = [];

        // Index changesets by table+rowKey for fast lookup.
        $oursInserts = self::indexByTableAndKey($oursChangeset->inserts());
        $oursUpdates = self::indexByTableAndKey($oursChangeset->updates());
        $oursDeletes = self::indexByTableAndKey($oursChangeset->deletes());
        $theirsInserts = self::indexByTableAndKey($theirsChangeset->inserts());
        $theirsUpdates = self::indexByTableAndKey($theirsChangeset->updates());
        $theirsDeletes = self::indexByTableAndKey($theirsChangeset->deletes());

        // Process theirs inserts.
        foreach ($theirsChangeset->inserts() as $insert) {
            $key = $insert->table . "\x00" . $insert->rowKey;
            if (isset($oursInserts[$key])) {
                // Both inserted same PK: conflict.
                $conflicts[] = new Conflict(
                    $insert->table,
                    $insert->rowKey,
                    'insert_insert',
                    null,
                    $oursInserts[$key]->values,
                    $insert->values,
                );
            } else {
                $operations[] = new MergeOperation(
                    MergeOperation::TYPE_INSERT,
                    $insert->table,
                    $insert->rowKey,
                    $insert->values,
                    'theirs',
                );
            }
        }

        // Process ours inserts (skip those already handled as conflicts).
        foreach ($oursChangeset->inserts() as $insert) {
            $key = $insert->table . "\x00" . $insert->rowKey;
            if (!isset($theirsInserts[$key])) {
                $operations[] = new MergeOperation(
                    MergeOperation::TYPE_INSERT,
                    $insert->table,
                    $insert->rowKey,
                    $insert->values,
                    'ours',
                );
            }
        }

        // Process theirs updates.
        foreach ($theirsChangeset->updates() as $update) {
            $key = $update->table . "\x00" . $update->rowKey;

            if (isset($oursDeletes[$key])) {
                // Theirs updated, ours deleted: conflict.
                $conflicts[] = new Conflict(
                    $update->table,
                    $update->rowKey,
                    'delete_update',
                    null,
                    null,
                    $update->fullRow,
                );
                continue;
            }

            if (isset($oursUpdates[$key])) {
                // Both updated same row: column-level merge.
                $baseRow = $base->getTable($update->table)?->getRow($update->rowKey) ?? [];
                $oursRow = $ours->getTable($update->table)?->getRow($update->rowKey) ?? [];
                $theirsRow = $theirs->getTable($update->table)?->getRow($update->rowKey) ?? [];

                $result = ColumnMerge::merge(
                    $update->table,
                    $update->rowKey,
                    $baseRow,
                    $oursRow,
                    $theirsRow,
                );

                if ($result['conflicts'] !== []) {
                    array_push($conflicts, ...$result['conflicts']);
                }

                $operations[] = new MergeOperation(
                    MergeOperation::TYPE_UPDATE,
                    $update->table,
                    $update->rowKey,
                    $result['values'],
                    'merged',
                );
                continue;
            }

            // Only theirs updated: accept.
            $operations[] = new MergeOperation(
                MergeOperation::TYPE_UPDATE,
                $update->table,
                $update->rowKey,
                $update->fullRow,
                'theirs',
            );
        }

        // Process ours updates (only those not already handled).
        foreach ($oursChangeset->updates() as $update) {
            $key = $update->table . "\x00" . $update->rowKey;

            if (isset($theirsDeletes[$key])) {
                // Ours updated, theirs deleted: conflict.
                $conflicts[] = new Conflict(
                    $update->table,
                    $update->rowKey,
                    'update_delete',
                    null,
                    $update->fullRow,
                    null,
                );
                continue;
            }

            if (isset($theirsUpdates[$key])) {
                // Already handled in theirs updates loop.
                continue;
            }

            // Only ours updated: accept.
            $operations[] = new MergeOperation(
                MergeOperation::TYPE_UPDATE,
                $update->table,
                $update->rowKey,
                $update->fullRow,
                'ours',
            );
        }

        // Process theirs deletes.
        foreach ($theirsChangeset->deletes() as $delete) {
            $key = $delete->table . "\x00" . $delete->rowKey;

            if (isset($oursUpdates[$key])) {
                // Already handled: update_delete conflict from theirs side.
                // Actually this is handled from ours perspective as update_delete.
                // From theirs perspective it's the same conflict, already emitted above
                // as delete_update when processing theirs updates. But actually:
                // theirs deleted + ours updated = conflict.
                // This was NOT handled above (theirs deletes vs ours updates).
                // The ours updates loop handles ours-updated+theirs-deleted.
                // So skip here.
                continue;
            }

            if (isset($oursDeletes[$key])) {
                // Both deleted: agree, emit one delete.
                $operations[] = new MergeOperation(
                    MergeOperation::TYPE_DELETE,
                    $delete->table,
                    $delete->rowKey,
                    $delete->oldValues,
                    'merged',
                );
                continue;
            }

            // Only theirs deleted: accept.
            $operations[] = new MergeOperation(
                MergeOperation::TYPE_DELETE,
                $delete->table,
                $delete->rowKey,
                $delete->oldValues,
                'theirs',
            );
        }

        // Process ours deletes (only those not already handled).
        foreach ($oursChangeset->deletes() as $delete) {
            $key = $delete->table . "\x00" . $delete->rowKey;

            if (isset($theirsUpdates[$key])) {
                // Already handled as delete_update conflict.
                continue;
            }

            if (isset($theirsDeletes[$key])) {
                // Already handled as both-deleted above.
                continue;
            }

            // Only ours deleted: accept.
            $operations[] = new MergeOperation(
                MergeOperation::TYPE_DELETE,
                $delete->table,
                $delete->rowKey,
                $delete->oldValues,
                'ours',
            );
        }

        return new MergeResult($operations, $conflicts);
    }

    /**
     * @template T of RowInsert|RowUpdate|RowDelete
     * @param list<T> $items
     * @return array<string, T>
     */
    private static function indexByTableAndKey(array $items): array
    {
        $index = [];
        foreach ($items as $item) {
            $index[$item->table . "\x00" . $item->rowKey] = $item;
        }

        return $index;
    }
}
