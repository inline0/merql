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

        $operations = $result->operations();
        $resolvedConflicts = [];

        foreach ($result->conflicts() as $conflict) {
            $resolved = self::resolveConflict($conflict, $policy);
            if ($resolved !== null) {
                $operations[] = $resolved;
            }
        }

        return new MergeResult($operations, $resolvedConflicts);
    }

    private static function resolveConflict(
        Conflict $conflict,
        ConflictPolicy $policy,
    ): ?MergeOperation {
        $useOurs = $policy === ConflictPolicy::OursWins;
        $table = $conflict->table();
        $rowKey = $conflict->rowKey();

        return match ($conflict->type()) {
            'update_update' => null,
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
