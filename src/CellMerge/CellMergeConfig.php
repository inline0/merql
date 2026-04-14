<?php

declare(strict_types=1);

namespace Merql\CellMerge;

/**
 * Configuration for cell-level merge strategies.
 *
 * Assign CellMerger implementations to specific columns or column types.
 * Columns without an explicit merger use the default (opaque comparison).
 */
final class CellMergeConfig
{
    /** @var array<string, CellMerger> Column name to merger (table.column or just column). */
    private array $columnMergers = [];

    /** @var array<string, CellMerger> Column type pattern to merger. */
    private array $typeMergers = [];

    private CellMerger $default;

    public function __construct()
    {
        $this->default = new OpaqueCellMerger();
    }

    /**
     * Assign a merger to a specific column.
     *
     * @param string $column Column name ("content") or qualified ("posts.content").
     */
    public function forColumn(string $column, CellMerger $merger): self
    {
        $this->columnMergers[$column] = $merger;

        return $this;
    }

    /**
     * Assign a merger to columns matching a type pattern.
     * Pattern is matched case-insensitively against the column type string.
     *
     * @param string $typePattern e.g. "json", "text", "longtext"
     */
    public function forType(string $typePattern, CellMerger $merger): self
    {
        $this->typeMergers[strtolower($typePattern)] = $merger;

        return $this;
    }

    /**
     * Get the merger for a specific table + column + type.
     */
    public function getMerger(string $table, string $column, string $columnType = ''): CellMerger
    {
        // Qualified name takes priority.
        $qualified = $table . '.' . $column;
        if (isset($this->columnMergers[$qualified])) {
            return $this->columnMergers[$qualified];
        }

        // Unqualified column name.
        if (isset($this->columnMergers[$column])) {
            return $this->columnMergers[$column];
        }

        // Match by type.
        $lowerType = strtolower($columnType);
        foreach ($this->typeMergers as $pattern => $merger) {
            if (str_contains($lowerType, $pattern)) {
                return $merger;
            }
        }

        return $this->default;
    }

    /**
     * Convenience: configure text merging for TEXT/LONGTEXT columns
     * and JSON merging for JSON columns.
     */
    public static function auto(): self
    {
        return (new self())
            ->forType('text', new TextCellMerger())
            ->forType('longtext', new TextCellMerger())
            ->forType('json', new JsonCellMerger());
    }
}
