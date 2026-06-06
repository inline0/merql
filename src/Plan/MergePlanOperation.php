<?php

declare(strict_types=1);

namespace Merql\Plan;

/**
 * UI/apply-ready merge operation.
 */
final readonly class MergePlanOperation
{
    /**
     * @param list<string> $identityColumns
     * @param array<string, scalar|null> $identityValues
     * @param array<string, scalar|null>|null $baseRow
     * @param array<string, scalar|null>|null $oursRow
     * @param array<string, scalar|null>|null $theirsRow
     * @param array<string, scalar|null> $resultValues
     * @param list<string> $changedColumns
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $table,
        public string $rowKey,
        public array $identityColumns,
        public array $identityValues,
        public string $source,
        public ?array $baseRow,
        public ?array $oursRow,
        public ?array $theirsRow,
        public array $resultValues,
        public array $changedColumns,
        public string $conflictState = 'clean',
        public ?string $sqlPreview = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'table' => $this->table,
            'row_key' => $this->rowKey,
            'identity_columns' => $this->identityColumns,
            'identity_values' => $this->identityValues,
            'source' => $this->source,
            'base_row' => $this->baseRow,
            'ours_row' => $this->oursRow,
            'theirs_row' => $this->theirsRow,
            'result_values' => $this->resultValues,
            'changed_columns' => $this->changedColumns,
            'conflict_state' => $this->conflictState,
            'sql_preview' => $this->sqlPreview,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::stringValue($data['id'] ?? ''),
            self::stringValue($data['type'] ?? ''),
            self::stringValue($data['table'] ?? ''),
            self::stringValue($data['row_key'] ?? ''),
            self::stringList($data['identity_columns'] ?? []),
            self::row($data['identity_values'] ?? []) ?? [],
            self::stringValue($data['source'] ?? ''),
            self::row($data['base_row'] ?? null),
            self::row($data['ours_row'] ?? null),
            self::row($data['theirs_row'] ?? null),
            self::row($data['result_values'] ?? []) ?? [],
            self::stringList($data['changed_columns'] ?? []),
            self::stringValue($data['conflict_state'] ?? 'clean'),
            is_string($data['sql_preview'] ?? null) ? $data['sql_preview'] : null,
        );
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
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

        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && (is_scalar($item) || $item === null)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }
}
