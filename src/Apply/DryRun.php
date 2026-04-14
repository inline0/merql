<?php

declare(strict_types=1);

namespace Merql\Apply;

use Merql\Driver\Driver;
use Merql\Merge\MergeResult;
use Merql\Snapshot\Snapshot;

/**
 * Preview SQL without executing.
 */
final class DryRun
{
    /**
     * Generate human-readable SQL statements.
     *
     * @param array<string, list<string>> $fkDependencies FK dependency map.
     * @return list<string>
     */
    public static function generate(
        MergeResult $result,
        ?Snapshot $base = null,
        array $fkDependencies = [],
        ?Driver $driver = null,
    ): array {
        $statements = SqlGenerator::generate($result, $base, $fkDependencies, $driver);
        $output = [];

        foreach ($statements as $stmt) {
            $sql = $stmt['sql'];
            $params = $stmt['params'];

            // Replace placeholders with quoted values for display.
            $i = 0;
            $replaced = preg_replace_callback('/\?/', function () use ($params, &$i) {
                $val = $params[$i++] ?? null;
                if ($val === null) {
                    return 'NULL';
                }

                return "'" . str_replace(
                    ['\\', "'", "\0", "\n", "\r"],
                    ['\\\\', "''", '\\0', '\\n', '\\r'],
                    (string) $val,
                ) . "'";
            }, $sql);
            $output[] = (string) $replaced;
        }

        return $output;
    }
}
