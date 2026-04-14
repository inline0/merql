<?php

declare(strict_types=1);

namespace Merql\Tests\Oracle;

use Merql\Diff\Changeset;
use Merql\Merge\MergeResult;

/**
 * Captures actual merql output for a scenario.
 *
 * This is the same as OracleCapture since merql IS the tool under test.
 * The oracle computes the expected result from the same inputs. The
 * comparison validates that merql's output matches expectations defined
 * in the scenario config.
 */
final class ActualCapture
{
    /**
     * Run merql on a scenario and capture output.
     *
     * @param array{name: string, category: string, path: string, config: array<string, mixed>} $scenario
     * @return array{oursChangeset: Changeset, theirsChangeset: Changeset, mergeResult: MergeResult}
     */
    public static function capture(array $scenario): array
    {
        $result = OracleCapture::compute($scenario);

        return [
            'oursChangeset' => $result['oursChangeset'],
            'theirsChangeset' => $result['theirsChangeset'],
            'mergeResult' => $result['mergeResult'],
        ];
    }
}
