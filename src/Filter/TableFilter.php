<?php

declare(strict_types=1);

namespace Merql\Filter;

/**
 * Include or exclude tables from snapshot and merge.
 */
final readonly class TableFilter
{
    /**
     * @param list<string> $include Patterns to include (empty = include all).
     * @param list<string> $exclude Patterns to exclude.
     */
    private function __construct(
        private array $include,
        private array $exclude,
    ) {
    }

    /**
     * @param list<string> $patterns Glob patterns to include.
     */
    public static function include(array $patterns): self
    {
        return new self($patterns, []);
    }

    /**
     * @param list<string> $patterns Glob patterns to exclude.
     */
    public static function exclude(array $patterns): self
    {
        return new self([], $patterns);
    }

    /**
     * @param list<string> $tables
     * @return list<string>
     */
    public function apply(array $tables): array
    {
        return array_values(array_filter($tables, function (string $table): bool {
            if ($this->exclude !== []) {
                foreach ($this->exclude as $pattern) {
                    if (fnmatch($pattern, $table)) {
                        return false;
                    }
                }
            }

            if ($this->include !== []) {
                foreach ($this->include as $pattern) {
                    if (fnmatch($pattern, $table)) {
                        return true;
                    }
                }

                return false;
            }

            return true;
        }));
    }
}
