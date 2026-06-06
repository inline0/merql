<?php

declare(strict_types=1);

namespace Merql\Rollback;

use Merql\Merge\MergeOperation;

/**
 * One inverse operation in a rollback plan.
 */
final readonly class RollbackOperation
{
    /**
     * @param array<string, scalar|null>|null $beforeRow
     * @param array<string, scalar|null>|null $expectedAfterRow
     */
    public function __construct(
        public string $sourceOperationId,
        public MergeOperation $inverseOperation,
        public ?array $beforeRow,
        public ?array $expectedAfterRow,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_operation_id' => $this->sourceOperationId,
            'inverse_operation' => [
                'type' => $this->inverseOperation->type,
                'table' => $this->inverseOperation->table,
                'row_key' => $this->inverseOperation->rowKey,
                'values' => $this->inverseOperation->values,
                'source' => $this->inverseOperation->source,
            ],
            'before_row' => $this->beforeRow,
            'expected_after_row' => $this->expectedAfterRow,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $inverse = is_array($data['inverse_operation'] ?? null) ? $data['inverse_operation'] : [];

        return new self(
            self::stringValue($data['source_operation_id'] ?? ''),
            new MergeOperation(
                self::stringValue($inverse['type'] ?? ''),
                self::stringValue($inverse['table'] ?? ''),
                self::stringValue($inverse['row_key'] ?? ''),
                self::row($inverse['values'] ?? []) ?? [],
                self::stringValue($inverse['source'] ?? 'rollback'),
            ),
            self::row($data['before_row'] ?? null),
            self::row($data['expected_after_row'] ?? null),
        );
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param mixed $value
     * @return array<string, scalar|null>|null
     */
    private static function row(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $row = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && (is_scalar($item) || $item === null)) {
                $row[$key] = $item;
            }
        }

        return $row;
    }
}
