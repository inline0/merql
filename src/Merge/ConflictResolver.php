<?php

declare(strict_types=1);

namespace Merql\Merge;

/**
 * Applies a conflict resolution policy to a merge result.
 */
final class ConflictResolver
{
    public static function resolve(MergeResult $result, ConflictPolicy $policy): MergeResult
    {
        if ($policy === ConflictPolicy::Manual) {
            return $result;
        }

        $useOurs = $policy === ConflictPolicy::OursWins;

        // For update_update conflicts, we need to patch existing operations.
        // Build a map of column-level patches per table+rowKey.
        $patches = self::buildColumnPatches($result->conflicts(), $useOurs);

        // Apply patches to existing operations.
        $operations = [];
        foreach ($result->operations() as $op) {
            $patchKey = $op->table . "\x00" . $op->rowKey;
            if (isset($patches[$patchKey])) {
                $values = $op->values;
                foreach ($patches[$patchKey] as $col => $val) {
                    $values[$col] = $val;
                }
                $operations[] = new MergeOperation(
                    $op->type,
                    $op->table,
                    $op->rowKey,
                    $values,
                    $op->source,
                );
            } else {
                $operations[] = $op;
            }
        }

        // Process non-column-level conflicts (insert_insert, update_delete, etc.).
        foreach ($result->conflicts() as $conflict) {
            if ($conflict->type() === 'update_update') {
                continue;
            }

            $resolved = self::resolveStructuralConflict($conflict, $useOurs);
            if ($resolved !== null) {
                $operations[] = $resolved;
            }
        }

        return new MergeResult(
            $operations,
            [],
            $result->baseSnapshot(),
            $result->schemaMismatches(),
        );
    }

    /**
     * Build column value patches from update_update conflicts.
     *
     * @param list<Conflict> $conflicts
     * @return array<string, array<string, mixed>>
     */
    private static function buildColumnPatches(array $conflicts, bool $useOurs): array
    {
        $patches = [];
        foreach ($conflicts as $conflict) {
            if ($conflict->type() !== 'update_update' || $conflict->column() === null) {
                continue;
            }

            $key = $conflict->table() . "\x00" . $conflict->rowKey();
            $patches[$key][$conflict->column()] = $useOurs
                ? $conflict->oursValue()
                : $conflict->theirsValue();
        }

        return $patches;
    }

    private static function resolveStructuralConflict(
        Conflict $conflict,
        bool $useOurs,
    ): ?MergeOperation {
        $table = $conflict->table();
        $rowKey = $conflict->rowKey();

        return match ($conflict->type()) {
            'update_delete' => $useOurs
                ? self::op(MergeOperation::TYPE_UPDATE, $table, $rowKey, (array) $conflict->oursValue(), 'ours')
                : self::op(MergeOperation::TYPE_DELETE, $table, $rowKey, [], 'theirs'),
            'delete_update' => $useOurs
                ? self::op(MergeOperation::TYPE_DELETE, $table, $rowKey, [], 'ours')
                : self::op(MergeOperation::TYPE_UPDATE, $table, $rowKey, (array) $conflict->theirsValue(), 'theirs'),
            'insert_insert' => $useOurs
                ? self::op(MergeOperation::TYPE_INSERT, $table, $rowKey, (array) $conflict->oursValue(), 'ours')
                : self::op(MergeOperation::TYPE_INSERT, $table, $rowKey, (array) $conflict->theirsValue(), 'theirs'),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function op(
        string $type,
        string $table,
        string $rowKey,
        array $values,
        string $source,
    ): MergeOperation {
        return new MergeOperation($type, $table, $rowKey, $values, $source);
    }
}
