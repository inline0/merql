<?php

declare(strict_types=1);

namespace Merql\Tests\Oracle;

/**
 * Discovers scenarios from the scenarios/ directory.
 */
final class ScenarioRepository
{
    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? dirname(__DIR__, 2) . '/scenarios';
    }

    /**
     * @return list<array{name: string, category: string, path: string, config: array<string, mixed>}>
     */
    public function all(): array
    {
        $scenarios = [];
        $categories = glob($this->baseDir . '/*', GLOB_ONLYDIR);

        foreach ($categories ?: [] as $categoryDir) {
            $category = basename($categoryDir);
            $scenarioDirs = glob($categoryDir . '/*', GLOB_ONLYDIR);

            foreach ($scenarioDirs ?: [] as $scenarioDir) {
                $configFile = $scenarioDir . '/scenario.json';
                if (!file_exists($configFile)) {
                    continue;
                }

                $config = json_decode(
                    (string) file_get_contents($configFile),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );

                $scenarios[] = [
                    'name' => $config['name'] ?? basename($scenarioDir),
                    'category' => $category,
                    'path' => $scenarioDir,
                    'config' => $config,
                ];
            }
        }

        return $scenarios;
    }

    /**
     * @return list<array{name: string, category: string, path: string, config: array<string, mixed>}>
     */
    public function byCategory(string $category): array
    {
        return array_values(array_filter(
            $this->all(),
            fn(array $s) => $s['category'] === $category,
        ));
    }

    /**
     * @return array{name: string, category: string, path: string, config: array<string, mixed>}|null
     */
    public function find(string $name): ?array
    {
        foreach ($this->all() as $scenario) {
            if ($scenario['name'] === $name) {
                return $scenario;
            }
        }

        return null;
    }
}
