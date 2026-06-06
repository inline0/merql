<?php

declare(strict_types=1);

namespace Merql\Plan;

/**
 * Serializable merge conflict.
 */
final readonly class MergePlanConflict
{
    /**
     * @param scalar|null|array<string, scalar|null> $oursValue
     * @param scalar|null|array<string, scalar|null> $theirsValue
     * @param scalar|null|array<string, scalar|null> $baseValue
     */
    public function __construct(
        public string $table,
        public string $rowKey,
        public string $type,
        public ?string $column,
        public string|int|float|bool|array|null $oursValue,
        public string|int|float|bool|array|null $theirsValue,
        public string|int|float|bool|array|null $baseValue,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'row_key' => $this->rowKey,
            'type' => $this->type,
            'column' => $this->column,
            'ours_value' => $this->oursValue,
            'theirs_value' => $this->theirsValue,
            'base_value' => $this->baseValue,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::stringValue($data['table'] ?? ''),
            self::stringValue($data['row_key'] ?? ''),
            self::stringValue($data['type'] ?? ''),
            is_string($data['column'] ?? null) ? $data['column'] : null,
            self::conflictValue($data['ours_value'] ?? null),
            self::conflictValue($data['theirs_value'] ?? null),
            self::conflictValue($data['base_value'] ?? null),
        );
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return scalar|null|array<string, scalar|null>
     */
    private static function conflictValue(mixed $value): string|int|float|bool|array|null
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

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
