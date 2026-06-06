<?php

declare(strict_types=1);

namespace Merql\Plan;

/**
 * Group of related merge operations.
 */
final readonly class MergePlanChangeGroup
{
    /**
     * @param array<string, scalar|null> $label
     * @param list<string> $operationIds
     * @param list<string> $relatedTables
     * @param array<string, int> $operationCounts
     * @param list<string> $sourceJournalIds
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $label,
        public string $primaryTable,
        public ?string $primaryRowKey,
        public array $operationIds,
        public array $relatedTables,
        public array $operationCounts,
        public array $sourceJournalIds = [],
        public string $conflictState = 'clean',
        public string $dependencyState = 'satisfied',
        public ?string $sqlPreviewSummary = null,
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
            'label' => $this->label,
            'primary_table' => $this->primaryTable,
            'primary_row_key' => $this->primaryRowKey,
            'operation_ids' => $this->operationIds,
            'related_tables' => $this->relatedTables,
            'operation_counts' => $this->operationCounts,
            'source_journal_ids' => $this->sourceJournalIds,
            'conflict_state' => $this->conflictState,
            'dependency_state' => $this->dependencyState,
            'sql_preview_summary' => $this->sqlPreviewSummary,
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
            self::scalarMap($data['label'] ?? []),
            self::stringValue($data['primary_table'] ?? ''),
            is_string($data['primary_row_key'] ?? null) ? $data['primary_row_key'] : null,
            self::stringList($data['operation_ids'] ?? []),
            self::stringList($data['related_tables'] ?? []),
            self::intMap($data['operation_counts'] ?? []),
            self::stringList($data['source_journal_ids'] ?? []),
            self::stringValue($data['conflict_state'] ?? 'clean'),
            self::stringValue($data['dependency_state'] ?? 'satisfied'),
            is_string($data['sql_preview_summary'] ?? null) ? $data['sql_preview_summary'] : null,
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
     * @return array<string, scalar|null>
     */
    private static function scalarMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && (is_scalar($item) || $item === null)) {
                $out[$key] = $item;
            }
        }

        return $out;
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
