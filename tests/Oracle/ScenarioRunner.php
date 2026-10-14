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
        try {
            $result = OracleCapture::compute($scenario);
            $comparison = ScenarioComparator::compare(
                $result['mergeResult'],
                $scenario['config'],
                $result['schemaMismatches'],
            );

            return [
                'name' => $scenario['name'],
                'pass' => $comparison['pass'],
                'failures' => $comparison['failures'],
            ];
        } catch (\Throwable $e) {
            return [
                'name' => $scenario['name'],
                'pass' => false,
                'failures' => [$e->getMessage()],
            ];
        }
    }

    /**
     * Run all scenarios and return results.
     *
     * @param list<array{name: string, category: string, path: string, config: array<string, mixed>}> $scenarios
     * @return list<array{name: string, pass: bool, failures: list<string>}>
     */
    public static function runAll(array $scenarios, int $jobs = 1): array
    {
        if ($jobs < 1) {
            throw new \InvalidArgumentException('Scenario jobs must be a positive integer.');
        }
        if ($jobs > 1 && count($scenarios) > 1) {
            return self::runParallel($scenarios, $jobs);
        }

        $results = [];
        foreach ($scenarios as $scenario) {
            $results[] = self::run($scenario);
        }

        return $results;
    }

    /**
     * @param list<array{name: string, category: string, path: string, config: array<string, mixed>}> $scenarios
     * @return list<array{name: string, pass: bool, failures: list<string>}>
     */
    private static function runParallel(array $scenarios, int $jobs): array
    {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('Parallel scenarios require proc_open().');
        }

        $active = [];
        $results = [];
        $next = 0;
        try {
            while ($next < count($scenarios) || $active !== []) {
                while ($next < count($scenarios) && count($active) < $jobs) {
                    $stdout = tmpfile();
                    $stderr = tmpfile();
                    if ($stdout === false || $stderr === false) {
                        throw new \RuntimeException('Cannot create scenario output files.');
                    }
                    $process = proc_open(
                        [
                            PHP_BINARY,
                            '-d',
                            'memory_limit=' . (ini_get('memory_limit') ?: '512M'),
                            __DIR__ . '/worker.php',
                        ],
                        [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr],
                        $pipes,
                    );
                    if (!is_resource($process)) {
                        fclose($stdout);
                        fclose($stderr);
                        throw new \RuntimeException('Cannot start scenario worker.');
                    }
                    $active[$next] = [$process, $stdout, $stderr];
                    fwrite($pipes[0], json_encode($scenarios[$next], JSON_THROW_ON_ERROR));
                    fclose($pipes[0]);
                    $next++;
                }

                foreach ($active as $index => [$process, $stdout, $stderr]) {
                    $status = proc_get_status($process);
                    if ($status['running']) {
                        continue;
                    }
                    $closed = proc_close($process);
                    $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
                    rewind($stdout);
                    rewind($stderr);
                    $decoded = json_decode((string) stream_get_contents($stdout), true);
                    $error = trim((string) stream_get_contents($stderr));
                    fclose($stdout);
                    fclose($stderr);
                    unset($active[$index]);

                    if (!is_array($decoded) || !isset($decoded['name'], $decoded['pass'], $decoded['failures'])) {
                        $results[$index] = [
                            'name' => $scenarios[$index]['name'],
                            'pass' => false,
                            'failures' => [$error !== '' ? $error : 'Scenario worker exited without a result.'],
                        ];
                    } else {
                        $results[$index] = [
                            'name' => $scenarios[$index]['name'],
                            'pass' => $exit === 0 && $decoded['pass'] === true,
                            'failures' => $decoded['failures'],
                        ];
                    }
                }
                if ($active !== []) {
                    usleep(10000);
                }
            }
        } finally {
            foreach ($active as [$process, $stdout, $stderr]) {
                proc_terminate($process);
                proc_close($process);
                fclose($stdout);
                fclose($stderr);
            }
        }
        ksort($results);

        return array_values($results);
    }
}
