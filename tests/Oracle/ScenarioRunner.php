<?php

declare(strict_types=1);

namespace Merql\Tests\Oracle;

/**
 * Orchestrates the full scenario pipeline: oracle, actual, compare.
 */
final class ScenarioRunner
{
    /**
     * Run a single scenario through the full pipeline.
     *
     * @param array{name: string, category: string, path: string, config: array<string, mixed>} $scenario
     * @return array{name: string, pass: bool, failures: list<string>}
     */
    public static function run(array $scenario): array
    {
        $result = OracleCapture::compute($scenario);
        $comparison = ScenarioComparator::compare(
            $result['mergeResult'],
            $scenario['config'],
        );

        return [
            'name' => $scenario['name'],
            'pass' => $comparison['pass'],
            'failures' => $comparison['failures'],
        ];
    }

    /**
     * Run all scenarios and return results.
     *
     * @param list<array{name: string, category: string, path: string, config: array<string, mixed>}> $scenarios
     * @return list<array{name: string, pass: bool, failures: list<string>}>
     */
    public static function runAll(array $scenarios): array
    {
        $results = [];
        foreach ($scenarios as $scenario) {
            $results[] = self::run($scenario);
        }

        return $results;
    }
}
