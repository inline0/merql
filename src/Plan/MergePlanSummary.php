<?php

declare(strict_types=1);

namespace Merql\Plan;

/**
 * Aggregate merge plan counts.
 */
final readonly class MergePlanSummary
{
    /**
     * @param array<string, int> $operationCounts
     * @param array<string, int> $tableCounts
     */
    public function __construct(
        public int $changeGroupCount,
        public int $operationCount,
        public int $conflictCount,
        public int $schemaMismatchCount,
        public array $operationCounts,
        public array $tableCounts,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'change_group_count' => $this->changeGroupCount,
            'operation_count' => $this->operationCount,
            'conflict_count' => $this->conflictCount,
            'schema_mismatch_count' => $this->schemaMismatchCount,
            'operation_counts' => $this->operationCounts,
            'table_counts' => $this->tableCounts,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::intValue($data['change_group_count'] ?? 0),
            self::intValue($data['operation_count'] ?? 0),
            self::intValue($data['conflict_count'] ?? 0),
            self::intValue($data['schema_mismatch_count'] ?? 0),
            self::intMap($data['operation_counts'] ?? []),
            self::intMap($data['table_counts'] ?? []),
        );
    }

    private static function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param mixed $value
     * @return array<string, int>
     */
    private static function intMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && is_numeric($item)) {
                $out[$key] = (int) $item;
            }
        }

        return $out;
    }
}
