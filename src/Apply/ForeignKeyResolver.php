<?php

declare(strict_types=1);

namespace Merql\Apply;

/**
 * Computes table dependency order from foreign key relationships.
 */
final class ForeignKeyResolver
{
    /**
     * Build dependency map from snapshot metadata.
     * Used when no PDO is available (offline mode).
     *
     * @param array<string, list<string>> $dependencies Child to parents mapping.
     * @param list<string> $tables Tables to order.
     * @return list<string> Tables in dependency order (parents first).
     */
    public static function topologicalSort(array $dependencies, array $tables): array
    {
        $ordered = [];
        $visited = [];
        $visiting = [];

        foreach ($tables as $table) {
            self::visit($table, $dependencies, $ordered, $visited, $visiting);
        }

        return $ordered;
    }

    /**
     * @param array<string, list<string>> $deps
     * @param list<string> $ordered
     * @param array<string, true> $visited
     * @param array<string, true> $visiting
     */
    private static function visit(
        string $table,
        array $deps,
        array &$ordered,
        array &$visited,
        array &$visiting,
    ): void {
        if (isset($visited[$table])) {
            return;
        }

        if (isset($visiting[$table])) {
            // Circular dependency: break the cycle.
            return;
        }

        $visiting[$table] = true;

        foreach ($deps[$table] ?? [] as $parent) {
            self::visit($parent, $deps, $ordered, $visited, $visiting);
        }

        unset($visiting[$table]);
        $visited[$table] = true;
        $ordered[] = $table;
    }

    /**
     * Sort operations by table dependency order.
     * Inserts/updates go parent-first, deletes go child-first.
     *
     * @param list<string> $tableOrder Tables in parent-first order.
     * @param list<\Merql\Merge\MergeOperation> $operations
     * @return list<\Merql\Merge\MergeOperation>
     */
    public static function sortOperations(
        array $tableOrder,
        array $operations,
    ): array {
        $tableIndex = array_flip($tableOrder);

        usort($operations, function ($a, $b) use ($tableIndex) {
            $aIdx = $tableIndex[$a->table] ?? PHP_INT_MAX;
            $bIdx = $tableIndex[$b->table] ?? PHP_INT_MAX;

            return $aIdx <=> $bIdx;
        });

        return $operations;
    }
}
