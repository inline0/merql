<?php

declare(strict_types=1);

namespace Merql\Identity;

use Merql\Schema\TableSchema;

/**
 * Table identity rule registry.
 */
final readonly class IdentityRuleSet
{
    /**
     * @param array<string, IdentityRule> $rules
     */
    public function __construct(
        private array $rules = [],
    ) {
    }

    public function ruleFor(TableSchema $schema): IdentityRule
    {
        return $this->rules[$schema->name] ?? IdentityRule::forSchema($schema);
    }

    public function with(string $table, IdentityRule $rule): self
    {
        $rules = $this->rules;
        $rules[$table] = $rule;

        return new self($rules);
    }

    /**
     * @param list<array<string, scalar|null>> $rows
     * @return list<IdentityConflict>
     */
    public function conflictsFor(string $table, IdentityRule $rule, array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $key = $rule->key($row);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $conflicts = [];
        foreach ($counts as $key => $count) {
            if ($count > 1) {
                $conflicts[] = new IdentityConflict($table, $key, $rule->columns, $count);
            }
        }

        return $conflicts;
    }

    /**
     * @return array<string, array{type: string, columns: list<string>}>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->rules as $table => $rule) {
            $out[$table] = $rule->toArray();
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rules = [];
        foreach ($data as $table => $rule) {
            if (is_string($table) && is_array($rule)) {
                $rules[$table] = IdentityRule::fromArray(self::stringKeyedArray($rule));
            }
        }

        return new self($rules);
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }
}
