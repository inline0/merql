<?php

declare(strict_types=1);

namespace Merql\Tests\Oracle;

use Merql\Exceptions\SchemaException;
use Merql\Merge\MergeResult;

/**
 * Compares expected vs actual merge results.
 */
final class ScenarioComparator
{
    /**
     * Compare merge result against scenario expectations.
     *
     * @param array<string, mixed> $config Scenario configuration.
     * @param list<SchemaException> $schemaMismatches
     * @return array{pass: bool, failures: list<string>}
     */
    public static function compare(
        MergeResult $result,
        array $config,
        array $schemaMismatches = [],
    ): array {
        $failures = [];
        $expectations = $config['expectations'] ?? [];

        if (isset($expectations['conflicts'])) {
            $expected = (int) $expectations['conflicts'];
            $actual = $result->conflictCount();
            if ($actual !== $expected) {
                $failures[] = "Expected {$expected} conflicts, got {$actual}";
            }
        }

        if (isset($expectations['operations'])) {
            $expected = (int) $expectations['operations'];
            $actual = $result->operationCount();
            if ($actual !== $expected) {
                $failures[] = "Expected {$expected} operations, got {$actual}";
            }
        }

        if (isset($expectations['is_clean'])) {
            $expected = (bool) $expectations['is_clean'];
            $actual = $result->isClean();
            if ($actual !== $expected) {
                $failures[] = "Expected isClean=" . ($expected ? 'true' : 'false')
                    . ", got " . ($actual ? 'true' : 'false');
            }
        }

        if (isset($expectations['conflict_details'])) {
            foreach ($expectations['conflict_details'] as $i => $detail) {
                if (!isset($result->conflicts()[$i])) {
                    $failures[] = "Missing conflict at index {$i}";
                    continue;
                }

                $conflict = $result->conflicts()[$i];

                if (isset($detail['table']) && $conflict->table() !== $detail['table']) {
                    $failures[] = "Conflict {$i}: expected table '{$detail['table']}',"
                        . " got '{$conflict->table()}'";
                }

                if (isset($detail['column']) && $conflict->column() !== $detail['column']) {
                    $failures[] = "Conflict {$i}: expected column '{$detail['column']}',"
                        . " got '{$conflict->column()}'";
                }

                if (isset($detail['type']) && $conflict->type() !== $detail['type']) {
                    $failures[] = "Conflict {$i}: expected type '{$detail['type']}',"
                        . " got '{$conflict->type()}'";
                }
            }
        }

        if (isset($expectations['schema_mismatches'])) {
            $expected = (int) $expectations['schema_mismatches'];
            $actual = count($schemaMismatches);
            if ($actual !== $expected) {
                $failures[] = "Expected {$expected} schema mismatches, got {$actual}";
            }
        }

        return ['pass' => $failures === [], 'failures' => $failures];
    }
}
